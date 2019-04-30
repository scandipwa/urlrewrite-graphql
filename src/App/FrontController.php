<?php
/**
 * @category  ScandiPWA
 * @package   ScandiPWA\Urlrewrite
 * @author    Vladimirs Mihnovics <info@scandiweb.com>
 * @copyright Copyright (c) 2019 Scandiweb, Ltd (http://scandiweb.com)
 * @license   OSL-3.0
 */
namespace ScandiPWA\UrlrewriteGraphQl\App;

use Magento\Catalog\Controller\Category\View as CategoryView;
use Magento\Catalog\Controller\Product\View as ProductView;
use Magento\Cms\Controller\Page\View as PageView;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\App\Request\ValidatorInterface as RequestValidator;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\App\Action\AbstractAction;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RouterListInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\FrontController as FrontControllerExtended;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FrontController extends FrontControllerExtended
{
    /**
     * @var RouterListInterface
     */
    protected $_routerList;

    /**
     * @var ResponseInterface
     */
    protected $response;

    /**
     * @var RequestValidator
     */
    private $requestValidator;

    /**
     * @var MessageManager
     */
    private $messages;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var bool
     */
    private $validatedRequest = false;

    /**
     * @var ResultInterface
     */
    public $result;


    public $actionInstance;

    /**
     * @param RouterListInterface $routerList
     * @param ResponseInterface $response
     * @param RequestValidator|null $requestValidator
     * @param MessageManager|null $messageManager
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        RouterListInterface $routerList,
        ResponseInterface $response,
        ?RequestValidator $requestValidator = null,
        ?MessageManager $messageManager = null,
        ?LoggerInterface $logger = null
    ) {
        parent :: __construct( $routerList, $response, $requestValidator, $messageManager, $logger );
        $this->_routerList = $routerList;
        $this->response = $response;
        $this->requestValidator = $requestValidator
            ?? ObjectManager::getInstance()->get(RequestValidator::class);
        $this->messages = $messageManager
            ?? ObjectManager::getInstance()->get(MessageManager::class);
        $this->logger = $logger
            ?? ObjectManager::getInstance()->get(LoggerInterface::class);
    }

    /**
     * Perform action and generate response
     *
     * @param RequestInterface|HttpRequest $request
     * @return ResponseInterface|ResultInterface
     * @throws \LogicException
     */
    public function dispatch( RequestInterface $request)
    {
        \Magento\Framework\Profiler::start('routers_match');
        $this->validatedRequest = false;
        $routingCycleCounter = 0;
        $result = null;
        while (!$request->isDispatched() && $routingCycleCounter++ < 100) {
            /** @var \Magento\Framework\App\RouterInterface $router */
            foreach ($this->_routerList as $router) {
                try {
                    $actionInstance = $router->match($request);
                    if ($actionInstance) {
                        $result = $this->processRequest(
                            $request,
                            $actionInstance
                        );
                        break;
                    }
                } catch (\Magento\Framework\Exception\NotFoundException $e) {
                    $request->initForward();
                    $request->setActionName('noroute');
                    $request->setDispatched(false);
                    break;
                }
            }
        }
        \Magento\Framework\Profiler::stop('routers_match');
        if ($routingCycleCounter > 100) {
            throw new \LogicException('Front controller reached 100 router match iterations');
        }
        $result->setAction($this->getRouteType($actionInstance));
        return $result;
    }

    /**
     * @param ActionInterface $actionInstance
     *
     * @return String
     */
    private function getRouteType(ActionInterface $actionInstance) {
        if($actionInstance instanceof ProductView) {
            return 'PRODUCT';
        } elseif ($actionInstance instanceof CategoryView) {
            return 'CATEGORY';
        } elseif ($actionInstance instanceof PageView) {
            return 'CMS_PAGE';
        } else {
            return 'NOT_FOUND';
        }
    }

    /**
     * @param HttpRequest $request
     * @param ActionInterface $actionInstance
     * @throws NotFoundException
     *
     * @return ResponseInterface|ResultInterface
     */
    private function processRequest(
        HttpRequest $request,
        ActionInterface $actionInstance
    ) {
        $request->setDispatched(true);
        $this->response->setNoCacheHeaders();
        $result = null;

        //Validating a request only once.
        if (!$this->validatedRequest) {
            try {
                $this->requestValidator->validate(
                    $request,
                    $actionInstance
                );
            } catch (InvalidRequestException $exception) {
                //Validation failed - processing validation results.
                $this->logger->debug(
                    'Request validation failed for action "'
                    .get_class($actionInstance) .'"'
                );
                $result = $exception->getReplaceResult();
                if ($messages = $exception->getMessages()) {
                    foreach ($messages as $message) {
                        $this->messages->addErrorMessage($message);
                    }
                }
            }
            $this->validatedRequest = true;
        }

        //Validation did not produce a result to replace the action's.
        if (!$result) {
            if ($actionInstance instanceof AbstractAction) {
                $result = $actionInstance->dispatch($request);
            } else {
                $result = $actionInstance->execute();
            }
        }

        //handling redirect to 404
        if ($result instanceof NotFoundException) {
            throw $result;
        }
        return $result;
    }
}
