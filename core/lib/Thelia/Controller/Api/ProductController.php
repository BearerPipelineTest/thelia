<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace Thelia\Controller\Api;

use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Thelia\Core\Event\Product\ProductCreateEvent;
use Thelia\Core\Event\Product\ProductDeleteEvent;
use Thelia\Core\Event\Product\ProductUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\Loop\Product;
use Thelia\Form\Api\Product\ProductCreationForm;
use Thelia\Form\Api\Product\ProductModificationForm;
use Thelia\Model\ProductQuery;

/**
 * Class ProductController
 * @package Thelia\Controller\Api
 * @author Manuel Raynaud <manu@thelia.net>
 */
class ProductController extends BaseApiController
{
    public function listAction()
    {
        $this->checkAuth(AdminResources::PRODUCT, [], AccessManager::VIEW);
        $request = $this->getRequest();

        if ($request->query->has('id')) {
            $request->query->remove('id');
        }

        $params = array_merge(
            $request->query->all(),
            [
                'limit' => $request->query->get('limit', 10),
                'offset' => $request->query->get('offset', 0)
            ]
        );

        return JsonResponse::create($this->baseProductSearch($params));
    }

    public function getProductAction($product_id)
    {
        $this->checkAuth(AdminResources::PRODUCT, [], AccessManager::VIEW);
        $request = $this->getRequest();

        $params = array_merge(
            $request->query->all(),
            ['id' => $product_id]
        );

        $results = $this->baseProductSearch($params);

        if ($results->isEmpty()) {
            throw new HttpException(404, sprintf('{"error": "product with id %d not found"}', $product_id));
        }

        return JsonResponse::create($results);
    }

    private function baseProductSearch($params)
    {
        $productLoop = new Product($this->getContainer());
        $productLoop->initializeArgs($params);

        $paginate = 0;
        return $productLoop->exec($paginate);
    }

    public function createAction()
    {
        $this->checkAuth(AdminResources::PRODUCT, [], AccessManager::CREATE);

        $request = $this->getRequest();
        $form = new ProductCreationForm($request, 'form', [], ['csrf_protection' => false]);

        try {
            $creationForm = $this->validateForm($form);

            $event = new ProductCreateEvent();
            $event->bindForm($creationForm);

            $this->dispatch(TheliaEvents::PRODUCT_CREATE, $event);

            $product = $event->getProduct();

            $updateEvent = new ProductUpdateEvent($product->getId());

            $updateEvent->bindForm($creationForm);

            $this->dispatch(TheliaEvents::PRODUCT_UPDATE, $updateEvent);

            $request->query->set('lang', $creationForm->get('locale')->getData());
            $response = $this->getProductAction($product->getId());
            $response->setStatusCode(201);

            return $response;
        } catch (\Exception $e) {
            return JsonResponse::create(['error' => $e->getMessage()], 500);
        }
    }

    public function updateAction($product_id)
    {
        $this->checkAuth(AdminResources::PRODUCT, [], AccessManager::UPDATE);

        $this->checkProductExists($product_id);

        $request = $this->getRequest();

        $form = new ProductModificationForm(
            $request,
            'form',
            ['id' => $product_id],
            [
                'csrf_protection' => false,
                'method' => 'PUT'
            ]
        );

        $data = $request->request->all();
        $data['id'] = $product_id;
        $request->request->add($data);

        try {
            $updateForm = $this->validateForm($form);

            $event = new ProductUpdateEvent($product_id);
            $event->bindForm($updateForm);

            $this->dispatch(TheliaEvents::PRODUCT_UPDATE, $event);

            return JsonResponse::create(null, 204);
        } catch (\Exception $e) {
            return JsonResponse::create(['error' => $e->getMessage()], 500);
        }
    }

    public function deleteAction($product_id)
    {
        $this->checkAuth(AdminResources::PRODUCT, [], AccessManager::DELETE);

        $this->checkProductExists($product_id);

        try {
            $event = new ProductDeleteEvent($product_id);

            $this->dispatch(TheliaEvents::PRODUCT_DELETE, $event);
            return Response::create('', 204);
        } catch (\Exception $e) {
            return JsonResponse::create(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @param $product_id
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    protected function checkProductExists($product_id)
    {
        $product = ProductQuery::create()
            ->findPk($product_id);

        if (null === $product) {
            throw new HttpException(404, sprintf('{"error": "product with id %d not found"}', $product_id));
        }
    }
}