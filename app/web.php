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
$web->add('/home','api/home'); // Home
$web->add('/v5/token','api/token'); // gerarToken :: /v5/token
$web->add('/pix/v1/dict/v2/key','api/key-old'); // consultarChaveAntigo :: /pix/v1/dict/v2/key
$web->add('/pix/v1/payment','api/payment'); // enviarPix :: /pix/v1/payment
$web->add('/baas-wallet-transactions-webservice/v1/pix/payment','api/payment-baas'); // enviarPix :: baas-wallet-transactions-webservice/v1/pix/payment
$web->add('/v5/merchant/balance','api/balance'); // saldo :: v5/merchant/balance
$web->add('/celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/external/{account}/','api/key');
// consultarChave :: celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/external/5692910/?key=ok@pix.com
