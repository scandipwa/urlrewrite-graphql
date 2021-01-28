<?php
/**
 * @category  ScandiPWA
 * @package   ScandiPWA\UrlrewriteGraphQl
 * @author    Vladimirs Mihnovics <info@scandiweb.com>
 * @copyright Copyright (c) 2019 Scandiweb, Ltd (http://scandiweb.com)
 * @license   OSL-3.0
 */
declare(strict_types=1);

namespace ScandiPWA\UrlrewriteGraphQl\Model\Resolver;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockStateInterface;
use Magento\CatalogInventory\Model\Stock\StockItemRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewriteGraphQl\Model\Resolver\UrlRewrite\CustomUrlLocatorInterface;

/**
 * UrlRewrite field resolver, used for GraphQL request processing.
 */
class EntityUrl implements ResolverInterface
{
    /**
     * Config key 'Display Out of Stock Products'
     */
    const XML_PATH_CATALOGINVENTORY_SHOW_OUT_OF_STOCK = 'cataloginventory/options/show_out_of_stock';

    /**
     * @var UrlFinderInterface
     */
    private $urlFinder;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var CustomUrlLocatorInterface
     */
    private $customUrlLocator;

    /**
     * @var CollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * @var StockItemRepository
     */
    private $stockItemRepository;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var StockStateInterface
     */
    private $stockState;

    /**
     * @param UrlFinderInterface $urlFinder
     * @param StoreManagerInterface $storeManager
     * @param CustomUrlLocatorInterface $customUrlLocator
     * @param CollectionFactory $productCollectionFactory
     * @param CategoryRepositoryInterface $categoryRepository
     * @param StockItemRepository $stockItemRepository
     * @param ScopeConfigInterface $scopeConfig
     * @param ProductRepositoryInterface $productRepository
     * @param StockStateInterface $stockState
     */
    public function __construct(
        UrlFinderInterface $urlFinder,
        StoreManagerInterface $storeManager,
        CustomUrlLocatorInterface $customUrlLocator,
        CollectionFactory $productCollectionFactory,
        CategoryRepositoryInterface $categoryRepository,
        StockItemRepository $stockItemRepository,
        ScopeConfigInterface $scopeConfig,
        ProductRepositoryInterface $productRepository,
        StockStateInterface $stockState
    ) {
        $this->urlFinder = $urlFinder;
        $this->storeManager = $storeManager;
        $this->customUrlLocator = $customUrlLocator;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryRepository = $categoryRepository;
        $this->stockItemRepository = $stockItemRepository;
        $this->scopeConfig = $scopeConfig;
        $this->productRepository = $productRepository;
        $this->stockState = $stockState;
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (!isset($args['url']) || empty(trim($args['url']))) {
            throw new GraphQlInputException(__('"url" argument should be specified and not empty'));
        }

        $result = null;
        $url = $args['url'];

        if (substr($url, 0, 1) === '/' && $url !== '/') {
            $url = ltrim($url, '/');
        }

        $customUrl = $this->customUrlLocator->locateUrl($url);
        $url = $customUrl ?: $url;
        $urlRewrite = $this->findCanonicalUrl($url);

        if ($urlRewrite) {
            $id = $urlRewrite->getEntityId();
            $type = $this->sanitizeType($urlRewrite->getEntityType());

            $result = [
                'id' => $id,
                'type' => $type,
                'canonical_url' => $urlRewrite->getTargetPath(),
            ];

            if ($type === 'PRODUCT') {
                // Using this instead of factory due https://github.com/magento/magento2/issues/12278
                $collection = $this->productCollectionFactory->create()
                    ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED]);
                $product = $collection->addIdFilter($id)->getFirstItem();
                $isInStock = false;

                $productType = $this->getProductType($id);

                if ($productType === 'configurable') {
                    $isInStock = $this->getConfigurableProductStockState($product);
                } else {
                    try {
                        $isInStock = $this->stockItemRepository->get($id)->getIsInStock();
                    } catch (NoSuchEntityException $e) {
                        // Ignoring error is safe
                    }
                }

                $isOutOfStockDisplay = $this->scopeConfig->getValue(
                    self::XML_PATH_CATALOGINVENTORY_SHOW_OUT_OF_STOCK,
                    ScopeInterface::SCOPE_STORE
                );

                /*
                 * return 404 page if product has no data
                 * or out of stock product display is disabled
                 */
                if (!$product->hasData() || !$isOutOfStockDisplay && !$isInStock) {
                    return null;
                }

                $result['sku'] = $product->getSku();
            } elseif ($type === 'CATEGORY') {
                $storeId = $this->storeManager->getStore()->getId();
                $category = $this->categoryRepository->get($id, $storeId);

                if (!$category->getIsActive()) {
                    return null;
                }
            }
        }

        return $result;
    }

    /**
     * Find the canonical url passing through all redirects if any
     *
     * @param string $requestPath
     * @return UrlRewrite|null
     */
    private function findCanonicalUrl(string $requestPath) : ?UrlRewrite
    {
        $urlRewrite = $this->findUrlFromRequestPath($requestPath);
        if ($urlRewrite && $urlRewrite->getRedirectType() > 0) {
            while ($urlRewrite && $urlRewrite->getRedirectType() > 0) {
                $urlRewrite = $this->findUrlFromRequestPath($urlRewrite->getTargetPath());
            }
        }
        if (!$urlRewrite) {
            $urlRewrite = $this->findUrlFromTargetPath($requestPath);
        }

        return $urlRewrite;
    }

    /**
     * Find a url from a request url on the current store
     *
     * @param string $requestPath
     * @return UrlRewrite|null
     * @throws NoSuchEntityException
     */
    private function findUrlFromRequestPath(string $requestPath) : ?UrlRewrite
    {
        return $this->urlFinder->findOneByData(
            [
                'request_path' => $requestPath,
                'store_id' => $this->storeManager->getStore()->getId()
            ]
        );
    }

    /**
     * Find a url from a target url on the current store
     *
     * @param string $targetPath
     * @return UrlRewrite|null
     * @throws NoSuchEntityException
     */
    private function findUrlFromTargetPath(string $targetPath) : ?UrlRewrite
    {
        return $this->urlFinder->findOneByData(
            [
                'target_path' => $targetPath,
                'store_id' => $this->storeManager->getStore()->getId()
            ]
        );
    }

    /**
     * Sanitize the type to fit schema specifications
     *
     * @param string $type
     * @return string
     */
    private function sanitizeType(string $type) : string
    {
        return strtoupper(str_replace('-', '_', $type));
    }

    private function getProductType($id): ?string
    {
        try {
            return $this->productRepository->getById($id)->getTypeId();
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    private function getConfigurableProductStockState($product) : bool
    {
        $totalStock = 0;
        if ($product->getTypeID() == 'configurable') {
            $productTypeInstance = $product->getTypeInstance();
            $usedProducts = $productTypeInstance->getUsedProducts($product);
            foreach ($usedProducts as $simple) {
                $totalStock += $this->stockState->getStockQty($simple->getId(), $simple->getStore()->getWebsiteId());
            }
        }

        return $totalStock > 0;
    }
}
