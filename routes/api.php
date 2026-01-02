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
        Route::get('/get-invoices-by-id', [ZohoPaymentController::class, 'getInvoiceDetails']);
        Route::get('/get-payments', [ZohoPaymentController::class, 'getPayments']);
        Route::post('/update-contact/{contact_id}', [ZohoCustomerController::class, 'updateContact']);
        Route::post('/create-online-payment', [ZohoPaymentController::class, 'createOnlinePayment']);

    });

       Route::prefix('warranty')->group(function () {
    
        Route::post('/wlead/store', [WleadController::class, 'store']);
        Route::post('/wlead/login', [WleadController::class, 'login']);
        Route::get('/wlead/list', [WleadController::class, 'index']);
        Route::post('/wlead/status/{id}', [WleadController::class, 'updateStatus']);
        Route::get('/wlead/report', [WleadController::class, 'yearMonthReport']);
    
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
    Route::post('/common/generate-user-code', [CommonUpdateController::class, 'generateUserCode']);



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


        Route::post('/create-product', [WarrantyController::class, 'createProduct']);
        Route::post('/update-product/{id}', [WarrantyController::class, 'updateProduct']);
        Route::get('/products-with-categories', [WarrantyController::class, 'getProductsWithCategories']);
    
        Route::post('/price-templates', [WarrantyController::class, 'addPriceTemplate']);
        Route::get('/price-templates', [WarrantyController::class, 'getPriceTemplates']);
        Route::post('/matching-price-templates', [WarrantyController::class, 'getMatchingPriceTemplates']);
    
        Route::post('/create-customer-new', [WCustomerController::class, 'createCustomerNew']);
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
        Route::get('/sale-records', [WCustomerController::class, 'deviceAnalytics']);
        
        Route::post('/customer/send-email-otp', [WCustomerController::class, 'sendCustomerEmailOtp']);
        Route::post('/customer/verify-email-otp', [WCustomerController::class, 'verifyCustomerEmailOtp']);
        Route::post('/customer/auth/google', [CommonAuthController::class, 'googleLoginCustomer']);


        Route::post('claim/raise', [WarrantyClaimController::class, 'raiseClaim']);
        Route::post('claim/verify-otp', [WarrantyClaimController::class, 'verifyOtp']);
        
        Route::post('claim/assign-employee', [WarrantyClaimController::class, 'assignEmployee']);
        Route::post('claim/pickup-otp-verify', [WarrantyClaimController::class, 'verifyPickupOtp']);
        
        Route::post('claim/inspection', [WarrantyClaimController::class, 'inspectionReport']);
        Route::post('claim/estimate-approve', [WarrantyClaimController::class, 'approveEstimate']);
        
        Route::post('claim/delivery-otp-verify', [WarrantyClaimController::class, 'verifyDeliveryOtp']);
        Route::post('claim/upload-photo', [WarrantyClaimController::class, 'uploadPhoto']);

        Route::prefix('customer/address')->group(function () {
            Route::post('list',   [WCustomerAddressController::class, 'list']);
            Route::post('create', [WCustomerAddressController::class, 'create']);
            Route::post('update', [WCustomerAddressController::class, 'update']);
            Route::post('delete', [WCustomerAddressController::class, 'delete']);
        });

      Route::post('retailers/by-pincode', [CompanyController::class, 'byPincode']);
      Route::get('claims/list', [WarrantyClaimController::class, 'list']);
      
    Route::post('add-coverages', [WProductCoverageController::class,'store']);

    Route::get('coverages/{productId}', [WProductCoverageController::class, 'index']);
    Route::put('coverages/update/{id}', [WProductCoverageController::class, 'update']);
    Route::delete('coverages/delete/{id}', [WProductCoverageController::class, 'destroy']);
        


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


