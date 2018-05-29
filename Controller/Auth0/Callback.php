<?php
namespace Magefox\SSOIntegration\Controller\Auth0;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magefox\SSOIntegration\Model\Auth0;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\CustomerExtractor;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Model\Session;
use Firebase\JWT\JWT;

class Callback extends \Magento\Framework\App\Action\Action
{
    /**
     * @var Validator
     */
    protected $formValidator;

    /**
     * @var Auth0
     */
    protected $auth0;

    protected $customerFactory;

    /**
     * @var \Magento\Customer\Model\CustomerExtractor
     */
    protected $customerExtractor;

    /**
     * @var \Magento\Customer\Api\AccountManagementInterface
     */
    protected $accountManagement;

    /**
     * @var Session
     */
    protected $customerSession;

    public function __construct(
        Context $context,
        Validator $formValidator,
        Auth0 $auth0,
        CustomerFactory $customerFactory,
        CustomerExtractor $customerExtractor,
        AccountManagementInterface $accountManagement,
        Session $customerSession
    ) {
        $this->auth0 = $auth0;
        $this->customerFactory = $customerFactory;
        $this->formValidator = $formValidator;
        $this->customerExtractor = $customerExtractor;
        $this->accountManagement = $accountManagement;
        $this->customerSession = $customerSession;
        parent::__construct($context);
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute()
    {
        $this->getRequest()->setParams(
            [
                'form_key' => $this->getRequest()->getParam('state')
            ]
        );

        /**
         * Validate form key
         */
        if ($this->formValidator->validate($this->getRequest())) {
            try {
                $token = $this->auth0->getToken($this->getRequest()->getParam('code'));
                if (isset($token['access_token']) || isset($token['id_token'])) {
                    $decodedToken = JWT::decode(
                        $token['id_token'],
                        $this->auth0->getJWTKey(),
                        Auth0::ALGORITHM
                    );

                    $user = $this->auth0->getUserInfo($token['access_token']);

                    $customer = $this->customerFactory
                        ->create()
                        ->loadByEmail($user['email']);

                    /**
                     * Customer register
                     */
                    if (!$customer->getId()) {
                        $customer = $this->customerExtractor->extract('customer_account_create', $this->getRequest());

                        $customer = $this->accountManagement
                            ->createAccount($customer, '', '');

                        $this->_eventManager->dispatch(
                            'customer_register_success',
                            ['account_controller' => $this, 'customer' => $customer]
                        );
                    }

                    /**
                     * Process login
                     */
                    if (!$user['email_verified']) {
                        $this->messageManager->addWarningMessage(__('You must confirm your account. Please check your email for the confirmation link.'));
                        $this->_redirect('/');
                        return;
                    } else {
                        $this->customerSession->loginById($customer->getId());
                    }
                }

                $this->messageManager->addSuccessMessage(__('Login successful.'));
                $this->_redirect('/');
                return;
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
                $this->_redirect('/');
                return;
            }
        } else {
            $this->messageManager->addErrorMessage(__('Invalid form key, please try again.'));
            $this->_redirect('/');
            return;
        }
    }
}