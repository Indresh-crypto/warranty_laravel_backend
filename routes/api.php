<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZohoCustomerController;
use App\Http\Controllers\ZohoContactController;
use App\Http\Controllers\ZohoItemController;
use App\Http\Controllers\ZohoInvoiceController;
use App\Http\Controllers\ZohoPaymentController;
use App\Http\Controllers\WleadController;
use App\Http\Controllers\CompanyEmployeeController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\RetailerController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\CommonAuthController;
use App\Http\Controllers\CommonUpdateController;
use App\Http\Controllers\WBadgeController;
use App\Http\Controllers\OnboardingPackageController;
use App\Http\Controllers\CompanyPackageAssignmentController;
use App\Http\Controllers\AgreementController;
use App\Http\Controllers\WarrantyController;
use App\Http\Controllers\WarrantyCardBuilderController;
use App\Http\Controllers\PaymentGatewayController;
use App\Http\Controllers\DummyCustomerController;
use App\Http\Controllers\WhatsappController;
use App\Http\Controllers\PaymentGatewayKeyController;
use App\Http\Controllers\IndiaPincodeController;
use App\Http\Controllers\WCustomerController;
use App\Http\Controllers\WarrantyDeviceModelController;
use App\Http\Controllers\WarrantyInvoiceController;
use App\Http\Controllers\WarrantyClaimController;
use App\Http\Controllers\WCustomerAddressController;
use App\Http\Controllers\WProductCoverageController;
use App\Http\Controllers\PhonePeController;
use App\Http\Controllers\RazorpayWebhookController;
use App\Http\Controllers\WarrantyPaymentFlowController;
use App\Http\Controllers\AdminPaymentController;
use App\Http\Controllers\WarrantyReportController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\EarningController;
use App\Http\Controllers\RetailerConnectionController;
use App\Http\Controllers\TaskAuthController;
use App\Http\Controllers\TaskTeamController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\TaskAiController;
use App\Http\Controllers\AIWarrantyController;
use App\Http\Controllers\TaskAiMessageTemplateController;
use App\Http\Controllers\TaskNotificationController;
use App\Http\Controllers\WhatsappWebhookController;
use App\Http\Controllers\TaskChatController;
use App\Http\Controllers\TaskTypeController;
use App\Http\Controllers\OrgUserMasterController;
use App\Http\Controllers\AnalyticsController;

use Illuminate\Support\Facades\Broadcast;
    
use App\Services\WhatsappService;
use App\Models\Company;
use App\Models\WDevice;

use App\Jobs\WarrantyPaymentFlowJob;

use App\Jobs\SendRetailerWonWhatsapp;
use App\Events\TaskChatTyping;
use App\Http\Controllers\SubscribedPackageController;
use App\Http\Controllers\CashfreeController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\CandidateController;

use App\Http\Controllers\PublicPackageController;
use App\Http\Controllers\EmailTrackingController;
use App\Http\Controllers\SalesReportController;

use App\Http\Controllers\WLeadRemarkController;

use Illuminate\Support\Facades\DB;



Route::options('{any}', function () {
    return response()->json([], 200);
})->where('any', '.*');


Route::prefix('candidates')->group(function () {
    Route::get('/',               [CandidateController::class, 'index']);
    Route::post('/import',        [CandidateController::class, 'import']);
    Route::post('/send-whatsapp', [CandidateController::class, 'sendWhatsapp']);
});

    Route::post('/razorpay/webhook', [RazorpayWebhookController::class, 'handle']);



Route::prefix('email-template')->group(function () {

    Route::post('/create', [EmailTemplateController::class, 'store']);
    Route::put('/update/{id}', [EmailTemplateController::class, 'update']);
    Route::patch('/status/{id}', [EmailTemplateController::class, 'changeStatus']);
    Route::get('/mapped-templates', [EmailTemplateController::class, 'mappedTemplates']);

    Route::post('/mapping', [EmailTemplateController::class, 'addMapping']);
    Route::post('/bulk-mapping', [EmailTemplateController::class, 'bulkMapping']);

    Route::get('/preview/{id}', [EmailTemplateController::class, 'preview']);
    Route::post('/send-test', [EmailTemplateController::class, 'sendTest']);
    Route::get('/tables', [EmailTemplateController::class, 'getTables']);
    Route::get('/columns/{table}', [EmailTemplateController::class, 'getColumns']);
    Route::get('/list', [EmailTemplateController::class, 'index']);
    Route::delete('/delete/{id}', [EmailTemplateController::class, 'destroy']);
});

Route::post('/uni-signup', [OrgUserMasterController::class, 'commonSignup']);
Route::post('/uni-create-zoho-contact', [OrgUserMasterController::class, 'createContact']);

Route::post('/verify-kyc', [CashfreeController::class, 'verify']);

Route::post('/verify-kyc-global', [CashfreeController::class, 'verifyGlobal']);


Route::get('/truncate-chat', [TaskChatController::class, 'truncateChatTables']);

Route::post('/update-zoho-access-token',[ZohoCustomerController::class, 'updateZohoAccessTokenFromApi']);


Broadcast::routes(['middleware' => []]);
    Route::post('/send-warranty-test', [WhatsappController::class, 'sendWarrantyTest']);
    Route::post('/test-invoice-whatsapp', [WhatsappController::class, 'testInvoiceWhatsapp']);

    Route::prefix('zoho')->group(function () {
        Route::get('/update-token', [ZohoCustomerController::class, 'updateZohoAccessToken']);
        
        Route::post('/zoho-users', [ZohoCustomerController::class, 'signupUser']);
        Route::get('/fetch-contacts', [ZohoContactController::class, 'fetchContacts']);
        Route::post('/create-contact', [ZohoCustomerController::class, 'createContact']);
        Route::get('/get-contacts', [ZohoCustomerController::class, 'getZohoContacts']);
        Route::post('/create-item', [ZohoItemController::class, 'createZohoItem']);
        Route::get('/get-zoho-item', [ZohoItemController::class, 'getZohoItems']);
        Route::post('/create-invoice', [ZohoInvoiceController::class, 'createZohoInvoice']);
        Route::get('/get-invoices', [ZohoInvoiceController::class, 'getInvoices']);
        Route::post('/create-payment', [ZohoPaymentController::class, 'createPayment']);
        Route::get('/get-invoices-by-id', [ZohoInvoiceController::class, 'getInvoiceDetails']);
        Route::get('/get-payments', [ZohoPaymentController::class, 'getPayments']);
        Route::post('/update-contact/{contact_id}', [ZohoCustomerController::class, 'updateContact']);
        Route::post('/create-online-payment', [ZohoPaymentController::class, 'createOnlinePayment']);
        
         Route::get('/sync-invoices', [ZohoInvoiceController::class, 'syncAllInvoices']);
         Route::get('/sync-payments', [ZohoPaymentController::class, 'syncAllPayments']);
         
        Route::get('/send-overdue-invoices-wa', [ZohoInvoiceController::class, 'getOverdueInvoicesWa']);


    });

       Route::prefix('warranty')->group(function () {
    
    
        Route::post('/add-wallet-balance',[CompanyController::class, 'paymentWalletBalance']);
        Route::get('/analytics/dashboard', [AnalyticsController::class, 'dashboard']);
        Route::get('/sales/report', [SalesReportController::class, 'report']);
        Route::get('/sales/compare', [SalesReportController::class, 'compare']);
        
        
        Route::get('/dashboard/summary', [AdminController::class, 'dashboardSummary']);
    
        Route::get('/dashboard/warranty-sales', [AdminController::class, 'warrantySales']);
        
        Route::get('/dashboard/leads', [AdminController::class, 'leadsDashboard']);
        
        Route::get('/dashboard/retailer-status', [AdminController::class, 'retailerStatusDashboard']);


    
        Route::get('/email/open/{id}', [EmailTrackingController::class, 'track']);
    
        Route::get('/product-sales-report', [WarrantyController::class, 'productSalesReport']);
        
        Route::get('/geo-data', [CompanyController::class, 'getGeoData']);
    
        Route::get('/onboarding-stats', [CompanyController::class, 'getOnboardingStats']);
    
        Route::post('/wlead/store', [WleadController::class, 'store']);
        Route::post('/wlead/login', [WleadController::class, 'login']);
        Route::get('/wlead/list', [WleadController::class, 'index']);
        Route::post('/wlead/status/{id}', [WleadController::class, 'updateStatus']);
        Route::get('/wlead/report', [WleadController::class, 'yearMonthReport']);
        Route::post('/wlead/update/{id}', [WleadController::class, 'update']);
        

    
    });
    
   Route::prefix('company')->group(function () {

        Route::post('/employee/store', [CompanyEmployeeController::class, 'store']);
        Route::post('/employee/login', [CompanyEmployeeController::class, 'login']);
        Route::post('/employee/all', [CompanyEmployeeController::class, 'allEmployees']);
        Route::post('/employee/search', [CompanyEmployeeController::class, 'search']);
    
        Route::post('/employee/update/{id}', [CompanyEmployeeController::class, 'update']);
        Route::post('/employee/change-password/{id}', [CompanyEmployeeController::class, 'changePassword']);
        
         Route::get('/bharat-data', [CompanyEmployeeController::class, 'employeeAreaWiseReport']);
         
         Route::get('/state-district-shop-data', [CompanyEmployeeController::class, 'stateDistrictShopCount']);
         
         Route::post('/employee/reset-password', [CompanyEmployeeController::class, 'resetEmployeePassword']);
         Route::post('/employee/set-password', [CompanyEmployeeController::class, 'setEmployeePassword']);
         
         Route::get('/employee-dashboard', [CompanyEmployeeController::class, 'employeeDashboard']);
         
        Route::get('/employee-sales-chart', [CompanyEmployeeController::class, 'salesBarChart']);


         Route::get('/employee-retailer-line-chart', [CompanyEmployeeController::class, 'retailerStatusSnapshot']);
         Route::get('/sync-wallet-from-zoho', [CompanyController::class, 'syncZohoWalletBalance']);
         
        Route::post('/create-warranty-with-credit', [WarrantyController::class, 'createDeviceWithInvoiceAndCredit']);

        Route::get('/retailer-transactions/{company_id}/{retailer_id}',  [WarrantyController::class, 'getRetailerTransactions']);
            

            
        Route::get('payment-invoices/{company_id}/{payment_id}', [WarrantyController::class, 'getInvoicesAgainstPayment']);
        Route::get('retailer-warranty-sales-report', [CompanyController::class, 'retailerSalesReport']);

        Route::get('promoter-warranty-sales-report', [CompanyController::class, 'promoterSalesReport']);

        Route::get('distributor-warranty-sales-report', [CompanyController::class, 'distributorSalesReport']);


    });
    
    
    Route::prefix('company')->group(function () {
    
        Route::post('/add', [CompanyController::class, 'store']);
        Route::post('/login', [CompanyController::class, 'login']);
        Route::get('/get/{id}', [CompanyController::class, 'getCompany']);
        Route::get('/list', [CompanyController::class, 'list']);
        Route::post('/status/{id}', [CompanyController::class, 'updateStatus']);
    });
    
    
    Route::prefix('admin')->group(function () {
        Route::post('/login', [AdminController::class, 'login']);
        Route::get('/profile/{id}', [AdminController::class, 'profile']);
    });
    
    Route::post('/common/login', [CommonAuthController::class, 'login']);
    Route::post('/common/update', [CommonUpdateController::class, 'updateOrCreate']);
    Route::get('/common/get-users', [CommonUpdateController::class, 'getCompanies']);
    Route::get('/common/get-subscribed-users', [CommonUpdateController::class, 'getSubscribedUsers']);

    Route::post('/common/generate-user-code', [CommonUpdateController::class, 'generateUserCode']);


    Route::post('/common/send-otp', [CommonAuthController::class, 'sendCompanyOtp']);
    Route::post('/common/verify-otp', [CommonAuthController::class, 'verifyCompanyOtp']);



    Route::post('/common/logout', [CommonAuthController::class, 'logout']);
    Route::get('/common/logout-status/{id}', [CommonAuthController::class, 'getLogoutStatus']);
    
    Route::post('/company/update-fields/{id}', [CommonUpdateController::class, 'updateDynamicFieldsCompany']);
    Route::get('/company/{companyId}/api-logs', [CommonUpdateController::class, 'getCompanyApiLogs']);
    
    Route::post('/common/set-mpin', [CommonAuthController::class, 'setMpin']);
    Route::post('/common/login-mpin', [CommonAuthController::class, 'loginWithMpin']);



    Route::get('/badges', [WBadgeController::class, 'index']);
    Route::get('/badges/{id}', [WBadgeController::class, 'show']);
    Route::post('/badges', [WBadgeController::class, 'store']);
    Route::post('/badges/{id}', [WBadgeController::class, 'update']);
    Route::delete('/badges/{id}', [WBadgeController::class, 'destroy']);

    Route::get('/packages', [OnboardingPackageController::class, 'index']);
    Route::get('/packages/{id}', [OnboardingPackageController::class, 'show']);
    Route::post('/packages', [OnboardingPackageController::class, 'store']);
    Route::put('/packages/{id}', [OnboardingPackageController::class, 'update']);
    Route::delete('/packages/{id}', [OnboardingPackageController::class, 'destroy']);

    Route::post('/user/upload-file', [CommonUpdateController::class, 'upload']);
    Route::get('/user/files/{email}', [CommonUpdateController::class, 'getFilesByEmail']);

    Route::get('/company-packages', [CompanyPackageAssignmentController::class, 'index']);
    Route::get('/company-packages/{id}', [CompanyPackageAssignmentController::class, 'show']);
    Route::post('/company-packages', [CompanyPackageAssignmentController::class, 'store']);

    Route::get('/agreement/{type}/{id}', [AgreementController::class, 'generateAgreement']);
    Route::post('/upload-for-esign/{type}/{id}', [AgreementController::class, 'uploadEsignDocument']);


    Route::prefix('warranty')->group(function () {
        
        
         Route::post('/remark/add', [WLeadRemarkController::class, 'addRemark']);
    
        Route::get('/remarks/{lead_id}', [WLeadRemarkController::class, 'getRemarks']);
    
        Route::delete('/remark/delete/{id}', [WLeadRemarkController::class, 'deleteRemark']);
        
        
        Route::get('/check-retailer-active-plan', [SubscribedPackageController::class, 'checkActivePlan']);


        Route::post('/create-warranty-with-credit', [WarrantyController::class, 'createDeviceWithInvoiceAndCredit']);
        Route::post('/create-warranty-with-subscription', [WarrantyController::class, 'createDeviceWithInvoiceAndSubscription']);

        Route::post('/buy-subscription-with-credit', [SubscribedPackageController::class, 'buyPackageWithCredit']);

        Route::post('/buy-subscription-with-offer', [SubscribedPackageController::class, 'buyPackageWithOffer']);


        Route::post('/create-warranty-by-customer', [WarrantyController::class, 'createDeviceByCustomer']);

        Route::post('/ai/warranty', [AIWarrantyController::class, 'ask']);

        Route::get('subscriptions', [SubscribedPackageController::class, 'index']);
        Route::post('/create-brand', [WarrantyController::class, 'createBrand']);
        Route::post('/update-brand/{id}', [WarrantyController::class, 'updateBrand']);
        Route::get('/get-brands', [WarrantyController::class, 'getBrands']);
        Route::post('/create-category', [WarrantyController::class, 'createCategory']);
        Route::post('/update-category/{id}', [WarrantyController::class, 'updateCategory']);
        Route::get('/get-categories', [WarrantyController::class, 'getCategories']);
        Route::post('/assign-categories', [WarrantyController::class, 'assignCategoriesToBrand']);
        Route::get('/brands-with-categories', [WarrantyController::class, 'getBrandsWithCategories']);
        Route::post('/upload-file', [WarrantyController::class, 'uploadFile']);
        Route::get('/warranty-dashboard', [WarrantyController::class, 'dashboardCounts']);
    
        Route::post('/device-models', [WarrantyDeviceModelController::class, 'storeDeviceModel']);

        Route::put('/device-models/{id}', [WarrantyDeviceModelController::class, 'updateDeviceModel']);

        Route::get('/device-models', [WarrantyDeviceModelController::class, 'storeDeviceModel']);

        Route::get('/device-models', [WarrantyDeviceModelController::class, 'searchDeviceModels']);

        Route::get('/variants', [WarrantyDeviceModelController::class, 'getVariants']);

        Route::post('/create-product', [WarrantyController::class, 'createProduct']);
        Route::post('/update-product/{id}', [WarrantyController::class, 'updateProduct']);
        Route::get('/products-with-categories', [WarrantyController::class, 'getProductsWithCategories']);
    
        Route::post('/price-templates', [WarrantyController::class, 'addPriceTemplate']);
        Route::post('/update-price-template/{id}', [WarrantyController::class, 'updatePriceTemplate']);
        Route::get('/price-templates', [WarrantyController::class, 'getPriceTemplates']);
        Route::post('/matching-price-templates', [WarrantyController::class, 'getMatchingPriceTemplates']);
        
        Route::post('/public-price-templates', [PublicPackageController::class, 'getPublicPriceTemplates']);


        Route::get('/price-report', [WarrantyController::class, 'priceReport']);
    
        Route::post('/create-customer-new', [WCustomerController::class, 'createCustomerNew']);
        
        Route::post('/send-customer-wa-otp', [WCustomerController::class, 'sendCustomerWaOtp']);
        Route::post('/verify-customer-wa-otp', [WCustomerController::class, 'verifyCustomerOtp']);

         
        Route::post('/create-warranty', [WarrantyController::class, 'createDevice']);
        Route::put('/update-customer/{id}', [WarrantyController::class, 'updateCustomer']);
        Route::get('/get-customers', [WCustomerController::class, 'getCustomers']);
        
        Route::get('/get-devices', [WCustomerController::class, 'getDevices']);
    
        Route::post('/assign-product', [WarrantyController::class, 'assignProduct']);
        
        Route::put('/company-product/status/{id}', [WarrantyController::class, 'updateProductStatus']);
        
        Route::get('/company-products', [WarrantyController::class, 'getCompanyProduct']);
    
        Route::post('/brand/toggle-status/{id}', [WarrantyController::class, 'toggleBrandStatus']);
        Route::post('/category/toggle-status/{id}', [WarrantyController::class, 'toggleCategoryStatus']);
    
        Route::post('/product/toggle-status/{id}', [WarrantyController::class, 'toggleStatusProduct']);
        
        Route::post('/update-status', [WarrantyController::class, 'updateWarrantyStatus']);
        
        Route::get('/get-customer-details', [WarrantyController::class, 'getWarrantyCustomerDetails']);
    
        Route::post('/check-customer', [WCustomerController::class, 'checkCustomerByMobile']);

        Route::post('/generate-wcertificate', [WarrantyController::class, 'generateDeviceCertificate']);
    
        Route::post('/update-warranty-status/{id}', [WCustomerController::class, 'updateWarrantyStatus']);
    
        Route::get('/analytics', [WarrantyController::class, 'getSoldSummery']);
        Route::get('/claim-list', [WarrantyCardBuilderController::class, 'getClaimList']);
        Route::post('claims/{id}/remarks', [WarrantyCardBuilderController::class, 'addRemark']);
        Route::get('claims/{id}/remarks', [WarrantyCardBuilderController::class, 'getRemarks']);
        Route::get('/analytics/monthly-sales', [WarrantyCardBuilderController::class, 'monthlySales']);
        Route::get('/promoter-wise-sales', [WarrantyCardBuilderController::class, 'promoterWiseSales']);
        Route::get('/promoter-wise-sales-list', [WarrantyCardBuilderController::class, 'promoterWiseSalesList']);

        Route::get('/generate-retailer-invoices', [WarrantyInvoiceController::class, 'createBulkInvoicesRetailerWise']);
        
        Route::get('/generate-company-invoices', [WarrantyInvoiceController::class, 'createBulkInvoicesCompanyOnlyWise']);


        Route::get('/sale-records', [WCustomerController::class, 'deviceAnalytics']);
        
        Route::post('/customer/send-email-otp', [WCustomerController::class, 'sendCustomerEmailOtp']);
        Route::post('/customer/verify-email-otp', [WCustomerController::class, 'verifyCustomerEmailOtp']);
        Route::post('/customer/auth/google', [CommonAuthController::class, 'googleLoginCustomer']);

        Route::post('/company/auth/google', [CommonAuthController::class, 'googleLoginCompany']);


        Route::post('claim/raise', [WarrantyClaimController::class, 'raiseClaim']);
        Route::post('claim/verify-otp', [WarrantyClaimController::class, 'verifyOtp']);
        
        Route::post('claim/assign-employee', [WarrantyClaimController::class, 'assignEmployee']);
        Route::post('claim/pickup-otp-verify', [WarrantyClaimController::class, 'verifyPickupOtp']);
        
        Route::post('claim/inspection', [WarrantyClaimController::class, 'inspectionReport']);
        Route::post('claim/estimate-approve', [WarrantyClaimController::class, 'approveEstimate']);
        
        Route::post('claim/delivery-otp-verify', [WarrantyClaimController::class, 'verifyDeliveryOtp']);
        Route::post('claim/upload-photo', [WarrantyClaimController::class, 'uploadPhoto']);


        Route::get('/earning-dashbard', [EarningController::class, 'index']);
     //   Route::get('/agent-earning', [EarningController::class, 'Agentdashboard']);
     
     
        Route::post('company/password/forgot', [CompanyController::class, 'sendResetLink']);
        Route::post('company/password/reset', [CompanyController::class, 'resetPassword']);


        Route::prefix('customer/address')->group(function () {
            Route::post('list',   [WCustomerAddressController::class, 'list']);
            Route::post('create', [WCustomerAddressController::class, 'create']);
            Route::post('update', [WCustomerAddressController::class, 'update']);
            Route::post('delete', [WCustomerAddressController::class, 'delete']);
        });

          Route::post('retailers/by-pincode', [CompanyController::class, 'byPincode']);
          Route::get('claims/list', [WarrantyClaimController::class, 'list']);
          Route::post('claims/employee', [WarrantyClaimController::class, 'employeeClaims']);
          Route::post('claims/assignments', [WarrantyClaimController::class, 'assignmentList']);
          
        Route::post('add-coverages', [WProductCoverageController::class,'store']);
    
        Route::get('coverages/{productId}', [WProductCoverageController::class, 'index']);
        Route::put('coverages/update/{id}', [WProductCoverageController::class, 'update']);
        Route::delete('coverages/delete/{id}', [WProductCoverageController::class, 'destroy']);
        
        Route::post('/company/approve-claim', [WarrantyClaimController::class,'approveClaim']);
        Route::post('/company/reject-claim', [WarrantyClaimController::class,'rejectClaim']);
        Route::get('claim-reasons', [WarrantyClaimController::class, 'claimReason']);
        
        Route::get('/payout-summary', [WCustomerController::class, 'payouts']);
        
       Route::post('/esign/webhook', [AgreementController::class, 'callback']);

       Route::post('/payment-flow', [WarrantyPaymentFlowController::class, 'processWarrantyPayment']);
       
       Route::put('/companies/{id}', [CompanyController::class, 'update']);
       
      Route::get('/agent-dashboard', [WarrantyController::class, 'agentDashboard']);
      
      Route::get('/analytics-dashboard', [WarrantyReportController::class, 'salesCreditSummary']);
      
      Route::get('/charts/revenue-trend', [WarrantyReportController::class, 'revenueTrend']);
      
      Route::get('/charts/product-revenue-share', [WarrantyReportController::class, 'productRevenueShare']);
      
      
      Route::get('/charts/geography-revenue', [WarrantyReportController::class, 'geographyRevenue']);

      Route::get('commission-dashboard', [CommissionController::class, 'dashboard']);
    
      Route::get('payouts/current-month', [CommissionController::class, 'currentMonthPayouts']);
      
      Route::get('payouts/product-wise', [CommissionController::class, 'payoutProductDetails']);

      Route::get('payouts/cp-payouts', [CommissionController::class, 'mcpChildPayouts']);

      Route::get('payout-warranties', [CommissionController::class, 'payoutRetailerDetails']);

      Route::get('payout-unbilled', [CommissionController::class, 'unbilledPayouts']);
 
       Route::get('payout-billed', [CommissionController::class, 'billedPayouts']);

    
        Route::post('/payout-request', [CommissionController::class, 'requestPayoutTransfer']);
        Route::post('/payout-verify-otp', [CommissionController::class, 'verifyPayoutOtp']);
        Route::post('/payout-approve', [CommissionController::class, 'approvePayout']);
        Route::post('/payout-transfer', [CommissionController::class, 'markTransferred']);

      Route::get('payout-statement', [CommissionController::class, 'payoutStatement']);

      Route::post('retailer-connection', [RetailerConnectionController::class, 'store']);
      Route::get('retailer-connection', [RetailerConnectionController::class, 'index']);
    
    
    Route::get('assigned-retailers', [CompanyEmployeeController::class, 'assignedRetailers']);
    Route::get('connected-retailers', [CompanyEmployeeController::class, 'connectedRetailers']);
    Route::get('using-retailers', [CompanyEmployeeController::class, 'usingProductRetailers']);
    
    Route::post('send-employee-otp', [CompanyEmployeeController::class, 'sendCompanyOtp']);
    Route::post('verify-employee-otp', [CompanyEmployeeController::class, 'verifyCompanyOtp']);

    Route::get('/company-map-dashboard', [CompanyController::class, 'dashboardCounts']);

    Route::get('/top-selling-retailers', [WarrantyReportController::class, 'topSellingRetailers']);
    

        
            Route::post('create-banner/',[BannerController::class,'createBanner']);
            Route::put('update-banner/{id}',[BannerController::class,'updateBanner']);
            Route::get('get-banner/',[BannerController::class,'listBanners']);
            Route::patch('banner-status/{id}/status',[BannerController::class,'changeStatus']);
            Route::delete('delete-banner/{id}',[BannerController::class,'deleteBanner']);
        
            Route::get('get-features', [WarrantyController::class,'getCoverageByType']);
            
            
            Route::post('/template-images', [CompanyController::class, 'storeTemplateImage']);

            Route::get('/template-images/{company_id}', [CompanyController::class, 'getTemplateImage']);

            Route::patch('/template-images/status/{id}', [CompanyController::class, 'updateTemplateImageStatus']);
            
            Route::delete('/template-images/{id}', [CompanyController::class, 'deleteTemplateImage']);
            
            
            Route::post('/gupshup/send-message', [WhatsappWebhookController::class, 'optInAndSendMessage']);
            Route::post('/webhook-inbound', [WhatsappWebhookController::class, 'handleWebhook']);
            Route::get('/whatsapp-messages', [WhatsappWebhookController::class, 'getMessages']);
    
    });

    Route::prefix('warrantybuilder')->group(function () {
        Route::get('/dashboard-cards', [WarrantyCardBuilderController::class, 'getDashboardCards']);
    });
    
    Route::get('/payment-gateways', [PaymentGatewayController::class, 'getGateways']);

    Route::get('/send-welcome-email/{id}', [WleadController::class, 'sendWelcomeEmail']);
    //
    Route::post('/verify-email-otp', [WleadController::class, 'verifyEmailOtp']);
    
    Route::get('/auto-zoho-contact-create', [ZohoCustomerController::class, 'createContactFromCompany']);

    Route::post('/cancel-invoice', [WarrantyInvoiceController::class, 'cancelWarrantyAndCreateCreditNote']);

    Route::get('/sync-company-w-invoice', [WarrantyInvoiceController::class, 'syncRetailerInvoicesFromZoho']);

    Route::get('/sync-admin-w-invoice', [WarrantyInvoiceController::class, 'syncParentInvoicesFromZoho']);

    Route::post('/pro-customers/store', [DummyCustomerController::class, 'store']);
    Route::get('/pro-customers', [DummyCustomerController::class, 'index']);
    Route::post('/pro-customer/update', [DummyCustomerController::class, 'update']);
    
    Route::post('/send-wa-otp', [WhatsappController::class, 'sendOtp']);
    Route::post('/verify-wa-otp', [WhatsappController::class, 'verifyOtp']);
    
    Route::post('/wa-test/{mobile}', [WhatsappController::class, 'sendWhatsAppTemplate']);


    Route::get('/payment/keys', [PaymentGatewayKeyController::class, 'index']);
    Route::post('/payment/keys', [PaymentGatewayKeyController::class, 'store']);
    Route::get('/payment/keys/{id}', [PaymentGatewayKeyController::class, 'show']);
    Route::put('/payment/keys/{id}', [PaymentGatewayKeyController::class, 'update']);
    Route::delete('/payment/keys/{id}', [PaymentGatewayKeyController::class, 'destroy']);
    Route::get('/pincode/{pincode}', [IndiaPincodeController::class, 'getByPincode']);
    
    Route::post('/phonepe/create-payment', [PhonePeController::class, 'createPayment']);
    Route::post('/phonepe/callback', [PhonePeController::class, 'callback']);
    Route::get('/phonepe/status/{txnId}', [PhonePeController::class, 'checkStatus']);

    
    
    Route::prefix('admin')->group(function () {

        Route::get('/payments', [AdminPaymentController::class, 'index']);
    
        Route::get('/payments/stats', [AdminPaymentController::class, 'stats']);
    
        Route::get('/payments/{paymentId}', [AdminPaymentController::class, 'show']);
    
        Route::get('/payments/{paymentId}/warranty-flow', [AdminPaymentController::class, 'warrantyStatus']);
    
        Route::post('/payments/{paymentId}/retry-warranty', [AdminPaymentController::class, 'retryWarranty']);
        
        Route::post('/payments/{paymentId}/regenerate-invoice',[AdminPaymentController::class, 'regenerateInvoice']);

    });


    Route::prefix('task')->group(function () {
    
            Route::post('/send-otp',[TaskAuthController::class, 'sendCompanyOtp']);
            Route::post('/verify-otp',[TaskAuthController::class, 'verifyCompanyOtp']);
            
            Route::post('/add-user', [TaskAuthController::class, 'addUser']);
            Route::put('/update-user/{id}', [TaskAuthController::class, 'updateUser']);
          
            Route::patch('/task-users/{id}/status', [TaskAuthController::class, 'changeStatus']);
            
            Route::post('/upload-picture', [TaskAuthController::class, 'uploadPicture']);
            
            Route::get('/all-users', [TaskAuthController::class, 'listUsers']);
            
            Route::get('/get-user-by-id/{id}', [TaskAuthController::class, 'getUserById']);
            Route::post('/create-team', [TaskTeamController::class, 'createTeam']);
            Route::get('/get-teams', [TaskTeamController::class, 'listTeams']);
            Route::get('/get-teams-by-id/{id}', [TaskTeamController::class, 'getTeamById']);
            Route::put('/update-team/{id}', [TaskTeamController::class, 'updateTeam']);
            
            
            Route::delete('/delete-team/{id}', [TaskTeamController::class, 'deleteTeam']);       
            Route::patch('/restore-team/{id}/restore', [TaskTeamController::class, 'restoreTeam']);
            
            Route::post('/create-task',[TaskController::class,'createTask']);
            Route::put('/update-task/{id}',[TaskController::class,'updateTask']);
            Route::post('/add-task-remark',[TaskController::class,'addRemark']);
            Route::get('/get-tasks',[TaskController::class,'listTasks']);
            Route::get('/dashboard', [TaskController::class, 'taskDashboard']);
            Route::post('/ai/task', [TaskAiController::class, 'ask']);
            Route::post('/{id}/read', [TaskController::class, 'markAsRead']);
            Route::get('/task-chart', [TaskController::class, 'taskChart']);
            Route::get('/manager-task-stats', [TaskController::class, 'managerTaskStats']);
            Route::get('/team-member-performances', [TaskController::class, 'teamMemberPerformance']);

            Route::get('/calendar-summary', [TaskController::class, 'taskCalendarSummary']);
            Route::get('/calendar-tasks', [TaskController::class, 'taskCalendarTasks']);
            Route::get('/employee-performances', [TaskController::class, 'employeeTaskChart']);
            Route::get('/performance-meter', [TaskTeamController::class, 'avgPerformance']);
           
                       
            Route::get('notifications/{userId}', [TaskNotificationController::class,'index']);
            
            Route::post('notifications/read/{id}', [TaskNotificationController::class,'markAsRead']);
            
            Route::post('notifications/read-all/{userId}', [TaskNotificationController::class,'markAllRead']);
            
            Route::get('notifications/unread/{userId}', [TaskNotificationController::class,'unreadCount']);
            
           Route::prefix('ai-templates')->group(function () {

                Route::get('/get-templates', [TaskAiMessageTemplateController::class, 'index']);
                Route::post('/store', [TaskAiMessageTemplateController::class, 'store']);
                Route::get('get-template-by-id/{id}', [TaskAiMessageTemplateController::class, 'show']);
                Route::post('/update-template/{id}', [TaskAiMessageTemplateController::class, 'update']);
                Route::delete('/delete-template/{id}', [TaskAiMessageTemplateController::class, 'destroy']);
            
            });
            
            
            Route::get('/task-types', [TaskTypeController::class, 'index']);
            Route::post('/task-types', [TaskTypeController::class, 'store']);
            Route::get('/task-types/{taskType}', [TaskTypeController::class, 'show']);
            Route::put('/task-types/{taskType}', [TaskTypeController::class, 'update']);
            Route::delete('/task-types/{taskType}', [TaskTypeController::class, 'destroy']);

            Route::prefix('chat')->group(function(){

            Route::get('conversations/{userId}',[TaskChatController::class,'conversations']);
        
            Route::get('messages/{conversationId}',[TaskChatController::class,'messages']);
        
            Route::post('send',[TaskChatController::class,'send']);
            Route::post('read/{conversationId}/{userId}',[TaskChatController::class,'read']);
        
           Route::post('typing', [TaskChatController::class, 'typing']);
           Route::post('/create', [TaskChatController::class, 'createConversation']);
           Route::post('/users-with-chats', [TaskChatController::class, 'teamChatUsers']);
           Route::post('/team-with-chats', [TaskChatController::class, 'teamChatList']);
           Route::post('/update-last-seen', [TaskChatController::class, 'updateLastSeen']);
           Route::post('/create-team-conversation/{teamId}', [TaskChatController::class, 'createTeamConversation']);
           Route::post('/save-device-token', [TaskNotificationController::class, 'saveToken']);
           Route::get('/task-search', [TaskChatController::class, 'searchTasks']);

        });

    });
    
    
    Route::get('/test-mail', function () {
        \Mail::raw('Mail test OK', function ($msg) {
            $msg->to('indresh@goelectronix.com')
                ->subject('Mail Test');
        });
    
        return 'Mail sent';
    });
    

Route::get('/test-whatsapp', function () {

    $retailer = Company::find(1);

    if (!$retailer) {
        return "Retailer not found";
    }

    // override mobile for testing
    $retailer->contact_phone = "9039128100";

    // dummy Zoho payment response
    $zohoPayment = [
        'payment_number' => 'RCPT-TEST-001',
        'payment_id' => '460000000TEST123'
    ];

    $amount = 300;

    // dummy Razorpay payment id
    $razorpayId = 'pay_TEST123456';

    app(WhatsappService::class)
        ->sendRetailerAdvancePaymentSuccess(
            $retailer,
            $zohoPayment,
            $amount,
            $razorpayId
        );

    return "WhatsApp test sent successfully";
});


Route::get('/test-invoice-whatsapp', function () {

    $device = WDevice::find(1); // testing device id

    if (!$device) {
        return "Device not found";
    }

    $retailer = Company::find($device->retailer_id);


    if (!$retailer) {
        return "Retailer not found";
    }

    // override mobile for testing
    $retailer->contact_phone = "9039128100";

    app(WhatsappService::class)
        ->sendRetailerInvoiceSuccess($retailer, $device);

    return "Invoice WhatsApp test sent";
});



Route::get('/test-retailer-wa', function () {

    $device = \App\Models\WDevice::latest()->first();
    $company = \App\Models\Company::find($device->retailer_id);

    app(\App\Services\WhatsappService::class)
        ->sendRetailerInvoiceSuccess($company, $device);

    return "Retailer WA test sent";
});


Route::get('/test-warranty-flow', function () {

    $payload = [

        'payment_id'    => 'TESTPAY-' . time(),

        'imei1'         => '123456789012345',

        'product_id'    => 22,   // existing warranty product id
        'company_id'    => 5927, // your company id
        'retailer_id'   => 5982, // retailer id

        'amount'        => 1000,

        'w_customer_id' => 3,   // existing customer id
        'model_id'      => 84,   // existing device model id

        'imei2'         => null,
        'serial'        => null,

        'agent_id'      => null,
        'created_by'    => 1,

        'document_url'  => null,
        'link1'         => null,
        'link2'         => null
    ];

    \Log::info('TEST WARRANTY FLOW TRIGGERED', $payload);

    WarrantyPaymentFlowJob::dispatch($payload);

    return response()->json([
        'success' => true,
        'message' => 'Warranty job dispatched',
        'payload' => $payload
    ]);

});


Route::get('/test-retailer-whatsapp', function () {

    $company = new Company();
    $company->id = 1; // fake id for testing
    $company->contact_phone = "9039128100";

    SendRetailerWonWhatsapp::dispatch($company->id);

    return response()->json([
        'status' => true,
        'message' => 'WhatsApp job dispatched'
    ]);
});