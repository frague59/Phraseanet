<?php
/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2015 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Controller;

use Alchemy\Phrasea\Application;
use Alchemy\Phrasea\Authentication\ACLProvider;
use Alchemy\Phrasea\Authentication\Authenticator;
use Alchemy\Phrasea\Model\Repositories\BasketElementRepository;
use Alchemy\Phrasea\Model\Repositories\FeedItemRepository;
use Alchemy\Phrasea\Model\Serializer\CaptionSerializer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PermalinkController extends AbstractDelivery
{
    /** @var ACLProvider */
    private $acl;
    /** @var \appbox */
    private $appbox;
    /** @var Authenticator */
    private $authentication;

    public function __construct(Application $app, \appbox $appbox, ACLProvider $acl, Authenticator $authenticator)
    {
        parent::__construct($app);

        $this->appbox = $appbox;
        $this->acl = $acl;
        $this->authentication = $authenticator;
    }

    public function getOptionsResponse(Request $request, $sbas_id, $record_id)
    {
        $databox = $this->getDatabox($sbas_id);
        $token = $request->query->get('token');
        $record = $this->retrieveRecord($databox, $token, $record_id, $request->get('subdef', 'thumbnail'));

        if (null === $record) {
            throw new NotFoundHttpException("Record not found");
        }

        return new Response('', 200, ['Allow' => 'GET, HEAD, OPTIONS']);
    }

    public function deliverCaption(Request $request, $sbas_id, $record_id)
    {
        $databox = $this->getDatabox($sbas_id);
        $token = $request->query->get('token');
        $record = $this->retrieveRecord($databox, $token, $record_id, \databox_subdef::CLASS_THUMBNAIL);

        if (null === $record) {
            throw new NotFoundHttpException("Caption not found");
        }
        $caption = $record->get_caption();

        return new Response($this->app['serializer.caption']->serialize($caption, CaptionSerializer::SERIALIZE_JSON), 200, ["Content-Type" => 'application/json']);
    }

    public function deliverPermaview(Request $request, $sbas_id, $record_id, $subdef)
    {
        return $this->doDeliverPermaview($sbas_id, $record_id, $request->query->get('token'), $subdef);
    }

    public function deliverPermaviewOldWay($sbas_id, $record_id, $token, $subdef)
    {
        return $this->doDeliverPermaview($sbas_id, $record_id, $token, $subdef);
    }

    public function deliverPermalink(Request $request, $sbas_id, $record_id, $subdef)
    {
        return $this->doDeliverPermalink($request, $sbas_id, $record_id, $request->query->get('token'), $subdef);
    }

    public function deliverPermalinkOldWay(Request $request, $sbas_id, $record_id, $token, $subdef)
    {
        return $this->doDeliverPermalink($request, $sbas_id, $record_id, $token, $subdef);
    }

    /**
     * @param \databox $databox
     * @param string   $token
     * @param int      $record_id
     * @param string   $subdef
     * @return \record_adapter
     */
    private function retrieveRecord(\databox $databox, $token, $record_id, $subdef)
    {
        /** @var FeedItemRepository $feedItemsRepository */
        $feedItemsRepository = $this->app['repo.feed-items'];
        if (in_array($subdef, [\databox_subdef::CLASS_PREVIEW, \databox_subdef::CLASS_THUMBNAIL])
            && $feedItemsRepository->isRecordInPublicFeed($databox->get_sbas_id(), $record_id)
        ) {
            return $databox->get_record($record_id);
        }

        $record = \media_Permalink_Adapter::challenge_token($this->app, $databox, $token, $record_id, $subdef);

        if (!($record instanceof \record_adapter)) {
            throw new NotFoundHttpException('Wrong token.');
        }

        return $record;
    }

    private function doDeliverPermaview($sbas_id, $record_id, $token, $subdef)
    {
        $databox = $this->getDatabox($sbas_id);
        $record = $this->retrieveRecord($databox, $token, $record_id, $subdef);

        return $this->app['twig']->render('overview.html.twig', [
            'subdef_name' => $subdef,
            'module_name' => 'overview',
            'module'      => 'overview',
            'view'        => 'overview',
            'record'      => $record,
        ]);
    }

    private function doDeliverPermalink(Request $request, $sbas_id, $record_id, $token, $subdef)
    {
        $databox = $this->getDatabox($sbas_id);
        $record = $this->retrieveRecord($databox, $token, $record_id, $subdef);

        $watermark = $stamp = false;

        if ($this->authentication->isAuthenticated()) {
            $watermark = !$this->acl->get($this->authentication->getUser())->has_right_on_base($record->get_base_id(), 'nowatermark');

            if ($watermark) {
                /** @var BasketElementRepository $repository */
                $repository = $this->app['repo.basket-elements'];

                if (count($repository->findReceivedValidationElementsByRecord($record, $this->authentication->getUser())) > 0) {
                    $watermark = false;
                } elseif (count($repository->findReceivedElementsByRecord($record, $this->authentication->getUser())) > 0) {
                    $watermark = false;
                }
            }
            $response = $this->deliverContent($request, $record, $subdef, $watermark, $stamp);

            $linkToCaption = $this->app->url("permalinks_caption", ['sbas_id' => $sbas_id, 'record_id' => $record_id, 'token' => $token]);
            $response->headers->set('Link', $linkToCaption);

            return $response;
        }

        $collection = \collection::get_from_base_id($this->app, $record->get_base_id());
        switch ($collection->get_pub_wm()) {
            default:
            case 'none':
                $watermark = false;
                break;
            case 'stamp':
                $stamp = true;
                break;
            case 'wm':
                $watermark = true;
                break;
        }

        $response = $this->deliverContent($request, $record, $subdef, $watermark, $stamp);

        $linkToCaption = $this->app->url("permalinks_caption", ['sbas_id' => $sbas_id, 'record_id' => $record_id, 'token' => $token]);
        $response->headers->set('Link', $linkToCaption);

        return $response;
    }

    /**
     * @param int $databoxId
     * @return \databox
     */
    private function getDatabox($databoxId)
    {
        return $this->appbox->get_databox((int)$databoxId);
    }
}
