<?php

# Web

$web = new App\Core\Web();

$web->add('/','home/home');

$web->add('/pages','pages/pages');

$web->add('/400','pages/400');
$web->add('/401','pages/401');
$web->add('/403','pages/403');
$web->add('/404','pages/404');
$web->add('/429','pages/429');
$web->add('/500','pages/500');
$web->add('/501','pages/501');
$web->add('/501','pages/501');
$web->add('/502','pages/502');
$web->add('/504','pages/504');

$web->add('/maintenance','pages/maintenance');

$web->add('/status','pages/status');
$web->add('/status-fake','pages/status-fake');

# API Celcoin
$web->add('/home','api/home');
$web->add('/endpoints','api/endpoints');
$web->add('/shots/{identifier}/','api/shots');
$web->add('/baas-webhookmanager/v1/webhook/subscription','api/webhook-subscription');
$web->add('/baas-webhookmanager/v1/webhook/subscription/entity','api/webhook-subscription');
$web->add('/baas-webhookmanager/v1/webhook/subscription/{entity}','api/webhook-subscription');
$web->add('/cslabs/webhook/dispatch','api/webhook-dispatch');

# Pix reverse
$web->add('/baas-wallet-transactions-webservice/v1/pix/reverse','api/pix-reverse-baas');
$web->add('/pix/v2/reverse/pi/{transactionId}','api/pix-reverse-old');
$web->add('/baas-wallet-transactions-webservice/v1/pix/payment/status','api/pix-payment-status-baas');
$web->add('/pix/v1/receivement/status','api/pix-payment-status-old');

# Wallet entry (lançamento manual)
$web->add('/baas-wallet-transactions-webservice/v1/wallet/entry/{account}','api/wallet-entry');

# DICT listar + claims (rotas mais específicas primeiro)
$web->add('/celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/{account}','api/dict-entry-list');
$web->add('/celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/list','api/dict-claim-list');
$web->add('/celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/confirm','api/dict-claim');
$web->add('/celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/cancel','api/dict-claim');
$web->add('/celcoin-baas-pix-dict-webservice/v1/pix/dict/claim','api/dict-claim');
$web->add('/celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/{id}','api/dict-claim-router');

# QR criar + location
$web->add('/pix/v1/brcode/static','api/brcode-static');
$web->add('/pix/v1/brcode/dynamic','api/brcode-dynamic');
$web->add('/pix/v1/collection/immediate','api/brcode-dynamic'); // mesmo retorno; alias
$web->add('/pix/v1/location/{locationId}/base64','api/location-base64');

# Charge cancel
$web->add('/baas/v2/charge/{txid}','api/charge-cancel');

# AccountManager extras
$web->add('/baas-accountmanager/v1/account/business','api/account-update-business');
$web->add('/baas-accountmanager/v1/account/close','api/account-close');
$web->add('/baas-accountmanager/v1/account/fetch-business','api/account-fetch-business');

$web->add('/v5/token','api/token'); // gerarToken :: /v5/token
$web->add('/v5/transactions/billpayments/authorize','api/billpayment-authorize'); // consultaPagamentos :: /v5/transactions/billpayments/authorize
$web->add('/baas/v2/billpayment','api/billpayment'); // efetuaPagamento :: /baas/v2/billpayment
$web->add('/baas/v2/billpayment/status','api/billpayment-status'); // confirmarPagamento :: /baas/v2/billpayment/status
$web->add('/api-integration-baas-webservice/v1/charge','api/charge'); // emissaoBoletoCobranca :: /api-integration-baas-webservice/v1/charge
$web->add('/pix/v1/dict/v2/key','api/key-old'); // consultarChaveAntigo :: /pix/v1/dict/v2/key
$web->add('/pix/v1/payment','api/payment'); // enviarPix :: /pix/v1/payment
$web->add('/baas-wallet-transactions-webservice/v1/pix/payment','api/payment-baas'); // enviarPix :: baas-wallet-transactions-webservice/v1/pix/payment
$web->add('/baas-wallet-transactions-webservice/v1/wallet/internal/transfer','api/internal-transfer');
$web->add('/baas-wallet-transactions-webservice/v1/wallet/internal/transfer/status','api/internal-transfer-status');
$web->add('/baas-wallet-transactions-webservice/v1/spb/transfer','api/spb-transfer');
$web->add('/baas/v2/pix/payment/status','api/pix-payment-status-baas');
$web->add('/pix/v1/payment/pi/status','api/pix-payment-status-old');
$web->add('/v5/merchant/balance','api/balance'); // saldo :: v5/merchant/balance
$web->add('/baas-walletreports/v1/wallet/balance','api/wallet-balance');
$web->add('/pix/v1/brcode/static/{transactionId}/base64','api/brcode-static-base64');
$web->add('/pix/v1/emv','api/emv');
$web->add('/pix/v1/collection/immediate/payload/{payload}','api/collection-payload');
$web->add('/pix/v1/collection/duedate/payload/{payload}','api/collection-payload');
$web->add('/celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/external/{account}/','api/key');
$web->add('/baas/v2/pix/dict/entry/external/{account}/','api/key'); // alias legado/v2
$web->add('/baas/v2/pix/dict/entry/external/{account}','api/key');
$web->add('/celcoin-baas-pix-dict-webservice/v1/pix/dict/entry','api/dict-entry-create');
$web->add('/celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/{key}','api/dict-entry-delete');

$web->add('/baas-onboarding/v1/account/check/','api/account-status');
$web->add('/baas-onboarding/v1/account/check','api/account-status');
$web->add('/baas-onboarding/v1/account/natural-person/create','api/onboarding-natural-person');
$web->add('/baas-onboarding/v1/account/business/create','api/onboarding-business');
$web->add('/baas-accountmanager/v1/account/fetch/','api/account');
$web->add('/baas-accountmanager/v1/account/fetch','api/account');
$web->add('/baas-accountmanager/v1/account/status','api/account-status-update');
$web->add('/baas-accountmanager/v1/account/natural-person','api/account-update-natural-person');

# Onboarding proposal (KYC moderno com webview)
$web->add('/onboarding/v1/onboarding-proposal/natural-person','api/onboarding-proposal-natural-person');
$web->add('/onboarding/v1/onboarding-proposal/legal-person','api/onboarding-proposal-legal-person');
$web->add('/onboarding/v1/onboarding-proposal','api/onboarding-proposal-list');

# Wallet/Reports — extrato e relatórios
$web->add('/baas-walletreports/v1/wallet/movement','api/wallet-movement');
$web->add('/baas/v2/wallet/movement','api/wallet-movement'); // alias v2 canônico
$web->add('/baas/v2/account/income-report','api/income-report');

# Conciliação
$web->add('/tools-conciliation/v1/ConsolidatedStatement','api/consolidated-statement');
$web->add('/tools-conciliation/v1/exportfile/types','api/exportfile-types');

