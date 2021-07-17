<?php

namespace WC\Components;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\Request;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Sale\Order;

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
     * @inheritDoc
     */
    public function processBeforeAction(Action $action): bool
    {
        switch ($action->getName()) {
            case 'silence':
                $type = $this->request->getQuery('type') ?? 'catalog';
                $mode = (string)$this->request->getQuery('work');
                $params = [
                    'TYPE_MODE' => json_encode(['TYPE' => $type, 'MODE' => $mode]),
                    'EXCHANGE_URL' => $this->request->getQuery('exchange')
                ];

                $login = (string)$this->request->getQuery('login');
                $password = (string)$this->request->getQuery('password');
                $unsignedParameters = ['LOGIN' => $login, 'PASSWORD' => $password];

                if (!$this->params = $this->getParams($params)) {
                    return false;
                }

                if (!$this->createHttpClient($unsignedParameters)) {
                    $this->addError(new Error('Error creating http client'));
                    return false;
                }

                break;
            case 'init':
                $params = $this->request->toArray() ?: [];
                if (!$this->params = $this->getParams($params)) {
                    return false;
                }

                if (!$this->createHttpClient($this->getUnsignedParameters())) {
                    $this->addError(new Error('Error creating http client'));
                    return false;
                }

                break;
        }

        return true;
    }

    /**
     * @return AjaxJson
     */
    public function prepareAction(): AjaxJson
    {
        $result = new Result();

        if (!$this->class::prepareTmpDir()) {
            $result->addError(new Error(Loc::getMessage('WC_DEBUG1C_PREPARE_DIR_ERROR')));
        }

        $isSuccess = $result->isSuccess() ? AjaxJson::STATUS_SUCCESS : AjaxJson::STATUS_ERROR;

        return new AjaxJson(null, $isSuccess, $result->getErrorCollection());
    }

    /**
     * Action for init.
     *
     * @return void
     */
    public function initAction(): void
    {
        $this->runAction('init');
    }

    /**
     * Action for silence.
     *
     * @return void
     */
    public function silenceAction(): void
    {
        // /bitrix/services/main/ajax.php?mode=ajax&c=wc:debug1c&action=silence&type=catalog&work=import&login=admin&password=admin
        $this->runAction('silence');
    }

    /**
     * @param string $mode Режим (для лога).
     *
     * @return void
     */
    private function runAction(string $mode) : void
    {
        $this->add2log('Mode:  ' . $mode . '||' . Loc::getMessage('WC_DEBUG1C_STARTED', ['#URL#' => $this->params['EXCHANGE_URL']]));

        if ($this->modeCheckAuth()) {
            $this->modeController();
        }

        $this->add2log(Loc::getMessage('WC_DEBUG1C_COMPLETED'));
    }

    /**
     * Подготовить параметры.
     *
     * @param array $params Параметры.
     *
     * @return array|null
     * @throws ArgumentException
     */
    private function getParams(array $params): ?array
    {
        // TYPE_MODE
        if ($params['TYPE_MODE'] && $dataType = Json::decode(htmlspecialcharsback($params['TYPE_MODE']))) {
            $params = array_merge($params, $dataType);
        } else {
            $this->addError(new Error(Loc::getMessage('WC_DEBUG1C_MODE_NOT_SELECTED')));
            $this->add2log(Loc::getMessage('WC_DEBUG1C_MODE_NOT_SELECTED'));
            return null;
        }

        // EXCHANGE_URL
        if ($exchangeUrl = $params['EXCHANGE_URL'] ?
            $this->class::getExchangeUrl($params['EXCHANGE_URL']) : $this->class::getExchangeUrl()) {
            $params['EXCHANGE_URL'] = $exchangeUrl;
        } else {
            $this->addError(new Error(Loc::getMessage('WC_DEBUG1C_FILE_NOT_EXIST', ['#FILE#' => $params['EXCHANGE_URL']])));
            $this->add2log(Loc::getMessage('WC_DEBUG1C_FILE_NOT_EXIST', ['#FILE#' => $params['EXCHANGE_URL']]));
            return null;
        }

        return $params;
    }

    private function add2log($str): void
    {
        $str = preg_replace("/[\\n]/", ' ', $str);
        $this->log .= date('d.m.y H:i:s') . ": $str \n";

        file_put_contents($this->logFile, $this->log);
    }

    /**
     * Клиент для запроса.
     *
     * @param array $unsignedParameters "Неподписанные" параметры.
     *
     * @return boolean
     */
    private function createHttpClient(array $unsignedParameters = []): bool
    {
        $this->httpClient = new HttpClient();

        if (!$unsignedParameters['LOGIN']) {
            $this->addError(new Error(Loc::getMessage('WC_DEBUG1C_EMPTY_PARAM', ['#PARAM#' => 'LOGIN'])));
            $this->add2log(Loc::getMessage('WC_DEBUG1C_EMPTY_PARAM', ['#PARAM#' => 'LOGIN']));
            return false;
        }
        if (!$unsignedParameters['PASSWORD']) {
            $this->addError(new Error(Loc::getMessage('WC_DEBUG1C_EMPTY_PARAM', ['#PARAM#' => 'PASSWORD'])));
            $this->add2log(Loc::getMessage('WC_DEBUG1C_EMPTY_PARAM', ['#PARAM#' => 'PASSWORD']));
            return false;
        }

        $this->httpClient->setAuthorization($unsignedParameters['LOGIN'], $unsignedParameters['PASSWORD']);

        $this->httpClient->get($this->params['EXCHANGE_URL']);
        $cookie = $this->httpClient->getCookies()->toArray();

        if (!$cookie['PHPSESSID']) {
            $this->addError(new Error(Loc::getMessage('WC_DEBUG1C_HTTP_CLIENT_CREATE_ERROR')));
            $this->add2log(Loc::getMessage('WC_DEBUG1C_HTTP_CLIENT_CREATE_ERROR'));
            return false;
        }

        $this->httpClient->setCookies(['PHPSESSID' => $cookie['PHPSESSID'], 'XDEBUG_SESSION' => 'PHPSTORM']); // todo в параметр

        return true;
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

        $this->addError(new Error(Loc::getMessage('WC_DEBUG1C_HTTP_CLIENT_AUTH_ERROR')));
        $this->add2log(Loc::getMessage('WC_DEBUG1C_HTTP_CLIENT_AUTH_ERROR'));

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
