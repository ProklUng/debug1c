<?php

namespace WC\Components;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\Request;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Sale\Order;
use RuntimeException;

Loc::loadMessages(__FILE__);
Loc::loadMessages(__DIR__ . '/class.php');

class Debug1CAjaxController extends Controller
{
    /**
     * @var Debug1C $class
     */
    private $class;

    /**
     * @var array $params
     */
    private $params;

    /**
     * @var HttpClient $httpClient
     */
    private $httpClient;

    /**
     * @var string $sessid
     */
    private $sessid;

    private $log;
    private $logFile;

    /**
     * Debug1CAjaxController constructor.
     *
     * @param Request|null $request Request.
     * @throws LoaderException
     */
    public function __construct(Request $request = null)
    {
        parent::__construct($request);

        Loader::includeModule('sale');

        $this->class = \CBitrixComponent::includeComponentClass('wc:debug1c');
        $this->logFile = $this->class::getPathLogFile();
    }

    /**
     * @inheritDoc
     */
    public function configureActions(): array
    {
        return [
            'init' => [
                'prefilters' => [], 'postfilters' => [],
            ],
            'prepare' => [
                'prefilters' => [], 'postfilters' => [],
            ],
            'silence' => [
                'prefilters' => [], 'postfilters' => [],
            ],
        ];
    }

    /**
     * @return string
     */
    public function prepareAction(): string
    {
        if (!$this->class::prepareTmpDir()) {
            $this->addError(new Error(Loc::getMessage('WC_DEBUG1C_PREPARE_DIR_ERROR')));
        }

        return count($this->getErrors()) === 0 ?
            Loc::getMessage('WC_DEBUG1C_PREPARE_DIR_SUCCESS') : Loc::getMessage('WC_DEBUG1C_PREPARE_DIR_ERROR');
    }

    /**
     * Action for init.
     *
     * @return void
     * @throws ArgumentException
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     * @throws NotImplementedException
     */
    public function initAction(): string
    {
        $this->add2log(Loc::getMessage('WC_DEBUG1C_STARTED'));

        $this->params = $this->getParams($this->request->toArray() ?: []);

        try {
            $this->httpClient = $this->createHttpClient();
        } catch (RuntimeException $e) {
            $this->add2log(Loc::getMessage('WC_DEBUG1C_COMPLETED'));
            return $e->getMessage();
        }

        if (empty($this->getErrors())) {
            $this->modeCheckAuth();
        }

        if (empty($this->getErrors())) {
            $this->modeController();
        }

        $this->add2log(Loc::getMessage('WC_DEBUG1C_COMPLETED'));

        return count($this->getErrors()) === 0 ?
            Loc::getMessage('WC_DEBUG1C_COMPLETED_SUCCESS') : Loc::getMessage('WC_DEBUG1C_COMPLETED_ERROR');
    }

    /**
     * Action for silence.
     *
     * @return string
     * @throws ArgumentException
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     * @throws NotImplementedException
     */
    public function silenceAction(): string
    {
        // /bitrix/services/main/ajax.php?mode=ajax&c=wc:debug1c&action=silence&type=catalog&work=import&login=admin&password=admin
        $this->add2log('Mode: silence || ' . Loc::getMessage('WC_DEBUG1C_STARTED', ['#URL#' => $this->params['EXCHANGE_URL']]));

        $type = $this->request->getQuery('type') ?? 'catalog';
        $mode = (string)$this->request->getQuery('work');
        $params = [
            'TYPE_MODE' => json_encode(['TYPE' => $type, 'MODE' => $mode]),
            'EXCHANGE_URL' => $this->request->getQuery('exchange')
        ];

        $login = (string)$this->request->getQuery('login');
        $password = (string)$this->request->getQuery('password');
        $unsignedParameters = ['LOGIN' => $login, 'PASSWORD' => $password];

        $this->params = $this->getParams($params);
        try {
            $this->httpClient = $this->createHttpClient($unsignedParameters);
        } catch (RuntimeException $e) {
            $this->add2log(Loc::getMessage('WC_DEBUG1C_COMPLETED'));
            return $e->getMessage();
        }

        if (count($this->getErrors()) === 0) {
            if ($this->modeCheckAuth()) {
                $this->modeController();
            }
        }

        $this->add2log(Loc::getMessage('WC_DEBUG1C_COMPLETED'));

        return count($this->getErrors()) === 0 ?
            Loc::getMessage('WC_DEBUG1C_COMPLETED_SUCCESS') : Loc::getMessage('WC_DEBUG1C_COMPLETED_ERROR');
    }

    /**
     * Подготовить параметры.
     *
     * @param array $params Параметры.
     *
     * @return array
     * @throws ArgumentException
     */
    private function getParams(array $params): array
    {
        // TYPE_MODE
        if ($params['TYPE_MODE'] && $dataType = Json::decode(htmlspecialcharsback($params['TYPE_MODE']))) {
            $params = array_merge($params, $dataType);
        } else {
            $this->add2logError(Loc::getMessage('WC_DEBUG1C_MODE_NOT_SELECTED'));
            return [];
        }

        // EXCHANGE_URL
        if ($exchangeUrl = $params['EXCHANGE_URL'] ?
            $this->class::getExchangeUrl($params['EXCHANGE_URL']) : $this->class::getExchangeUrl()) {
            $params['EXCHANGE_URL'] = $exchangeUrl;
        } else {
            $this->add2logError(Loc::getMessage('WC_DEBUG1C_FILE_NOT_EXIST', ['#FILE#' => $params['EXCHANGE_URL']]));
            return [];
        }

        return $params;
    }

    /**
     * @param string $message
     *
     * @return void
     */
    private function add2logError(string $message): void
    {
        $this->addError(new Error($message));
        $this->add2log($message);
    }

    /**
     * @param string $message
     *
     * @return void
     */
    private function add2log(string $message): void
    {
        $message = preg_replace("/[\\n]/", ' ', $message);
        $this->log .= date('d.m.y H:i:s') . ": $message \n";

        file_put_contents($this->logFile, $this->log);
    }

    /**
     * Клиент для запроса.
     *
     * @param array $unsignedParameters Неподписанные параметры.
     *
     * @return HttpClient
     * @throws RuntimeException
     */
    private function createHttpClient(array $unsignedParameters = []): HttpClient
    {
        $httpClient = new HttpClient();

        if (!$unsignedParameters) {
            $unsignedParameters = $this->getUnsignedParameters();
        }

        if (!$unsignedParameters['LOGIN']) {
            $this->add2logError(Loc::getMessage('WC_DEBUG1C_EMPTY_PARAM', ['#PARAM#' => 'LOGIN']));
            throw new RuntimeException(Loc::getMessage('WC_DEBUG1C_EMPTY_PARAM', ['#PARAM#' => 'LOGIN']));
        }

        if (!$unsignedParameters['PASSWORD']) {
            $this->add2logError(Loc::getMessage('WC_DEBUG1C_EMPTY_PARAM', ['#PARAM#' => 'PASSWORD']));
            throw new RuntimeException(Loc::getMessage('WC_DEBUG1C_EMPTY_PARAM', ['#PARAM#' => 'PASSWORD']));
        }

        $httpClient->setAuthorization($unsignedParameters['LOGIN'], $unsignedParameters['PASSWORD']);

        $httpClient->get($this->params['EXCHANGE_URL']);
        $cookie = $httpClient->getCookies()->toArray();

        if (!$cookie['PHPSESSID']) {
            $this->add2logError(Loc::getMessage('WC_DEBUG1C_HTTP_CLIENT_CREATE_ERROR'));
            throw new RuntimeException(Loc::getMessage('WC_DEBUG1C_HTTP_CLIENT_CREATE_ERROR'));
        }

        $httpClient->setCookies(['PHPSESSID' => $cookie['PHPSESSID'], 'XDEBUG_SESSION' => 'PHPSTORM']); // todo в параметр

        return $httpClient;
    }

    /**
     * @return boolean
     */
    private function modeCheckAuth(): bool
    {
        $url = "{$this->params['EXCHANGE_URL']}?type={$this->params['TYPE']}&mode=checkauth";
        $get = $this->convertEncoding($this->httpClient->get($url));

        preg_match('/sessid=(?!")\K.*/', $get, $sessid);

        if ($this->sessid = $sessid[0]) {
            $this->httpClient->setHeader('X-Bitrix-Csrf-Token', $this->sessid, true);
            $this->add2log(Loc::getMessage('WC_DEBUG1C_HTTP_CLIENT_AUTH_SUCCESS'));
            return true;
        }

        $this->add2logError(Loc::getMessage('WC_DEBUG1C_HTTP_CLIENT_AUTH_ERROR'));

        return false;
    }

    /**
     * @param string $str
     *
     * @return string
     */
    private function convertEncoding(string $str): string
    {
        return mb_convert_encoding($str, 'UTF-8', 'windows-1251'); // todo в параметр
    }

    /**
     * @return void
     * @throws ArgumentException
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     * @throws NotImplementedException
     */
    private function modeController(): void
    {
        switch ($this->params['TYPE']) {
            case 'catalog':
                switch ($this->params['MODE']) {
                    case 'import':
                        // $this->modeInit(); todo в параметр
                        if (!$file = $this->getImportFile('1c_catalog')) {
                            break;
                        }

                        $this->add2log(Loc::getMessage('WC_DEBUG1C_IMPORTING_FILE', ['#FILE#' => $file]));
                        $this->modeImport($file);
                        break;
                }
                break;
            case 'sale':
                switch ($this->params['MODE']) {
                    case 'import':
                        // $this->modeInit(); todo в параметр
                        if (!$file = $this->getImportFile('1c_exchange')) {
                            break;
                        }

                        $this->add2log(Loc::getMessage('WC_DEBUG1C_IMPORTING_FILE', ['#FILE#' => $file]));
                        $this->modeImport($file);
                        break;
                    case 'query':
                        $this->modeInit();
                        $this->modeQuery();
                        break;
                    case 'info':
                        $this->modeInfo();
                        break;
                    case 'exchange-order':
                        $this->add2log(Loc::getMessage('WC_DEBUG1C_SEARCHING_ORDER'));

                        if ($this->params['EXCHANGE_ORDER_ID'] > 0 && $order = Order::load($this->params['EXCHANGE_ORDER_ID'])) {
                            $this->add2log(Loc::getMessage('WC_DEBUG1C_ORDER_FOUND', ['#ORDER_ID#' => $this->params['EXCHANGE_ORDER_ID']]));
                        } else {
                            $this->add2log(
                                Loc::getMessage('WC_DEBUG1C_ORDER_NOT_FOUND', ['#ORDER_ID#' => $this->params['EXCHANGE_ORDER_ID']])
                            );
                            break;
                        }

                        $order->setField('UPDATED_1C', 'N');

                        if ($order->save()->isSuccess()) {
                            $this->add2log(Loc::getMessage('WC_DEBUG1C_ORDER_MARKED', ['#ORDER_ID#' => $this->params['QUERY_ORDER_ID']]));
                        } else {
                            $this->add2log(Loc::getMessage('WC_DEBUG1C_ORDER_NOT_UPDATED', ['#ORDER_ID#' => $this->params['QUERY_ORDER_ID']]));
                            break;
                        }
                        break;
                }
                break;
            case 'reference':
                // $this->modeInit(); todo в параметр
                if (!$file = $this->getImportFile('1c_highloadblock')) {
                    break;
                }

                $this->add2log(Loc::getMessage('WC_DEBUG1C_IMPORTING_FILE', ['#FILE#' => $file]));
                $this->modeImport($file);
                break;
        }
    }

    /**
     * @param string $dir Директория.
     *
     * @return string
     */
    private function getImportFile(string $dir): ?string
    {
        $files = scandir("{$_SERVER['DOCUMENT_ROOT']}/upload/$dir/", 1);

        foreach ($files as $file) {
            $info = new \SplFileInfo($file);

            if ($info->getExtension() === 'xml') {
                return $file;
            }
        }

        $this->add2log(Loc::getMessage('WC_DEBUG1C_FILE_NOT_FOUND'));

        return '';
    }

    /**
     * @param string $file Файл.
     *
     * @return void
     */
    private function modeImport(string $file): void
    {
        $url = "{$this->params['EXCHANGE_URL']}?type={$this->params['TYPE']}&mode={$this->params['MODE']}&sessid=$this->sessid&filename=$file";
        $get = $this->convertEncoding($this->httpClient->get($url));

        preg_match('/progress/', $get, $match);

        $this->add2log(Loc::getMessage('WC_DEBUG1C_REPLACE', ['#REPLACE#' => $get]));

        if ($match) {
            $this->modeImport($file);
        }
    }

    /**
     * @return void
     */
    private function modeInit(): void
    {
        $version = $this->params['VERSION'] ? "version={$this->params['VERSION']}" : '';
        $url = "{$this->params['EXCHANGE_URL']}?type={$this->params['TYPE']}&mode=init&sessid=$this->sessid&$version";

        if ($init = $this->convertEncoding($this->httpClient->get($url))) {
            $this->add2log(Loc::getMessage('WC_DEBUG1C_HTTP_CLIENT_INIT_SUCCESS'));
        }
    }

    /**
     * @return void
     */
    private function modeQuery(): void
    {
        $orderId = $this->params['QUERY_ORDER_ID'] ? "orderId={$this->params['QUERY_ORDER_ID']}" : '';
        $url = "{$this->params['EXCHANGE_URL']}?type={$this->params['TYPE']}&mode={$this->params['MODE']}&sessid=$this->sessid&$orderId";
        $get = $this->httpClient->get($url);

        file_put_contents($this->class::getPathOrderFile(), $get);
        $this->add2log(Loc::getMessage('WC_DEBUG1C_FILE_LINK', ['#FILE#' => $this->class::getPathOrderFile(false)]));
    }

    /**
     * @return void
     */
    private function modeInfo(): void
    {
        $url = "{$this->params['EXCHANGE_URL']}?type={$this->params['TYPE']}&mode={$this->params['MODE']}&sessid=$this->sessid";
        $get = $this->httpClient->get($url);

        file_put_contents($this->class::getPathFileInfo(), $get);
        $this->add2log(Loc::getMessage('WC_DEBUG1C_FILE_LINK', ['#FILE#' => $this->class::getPathFileInfo(false)]));
    }
}