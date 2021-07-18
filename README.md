## Изменения в форке

Введен режим запроса `silence` - для CLI или клиентов брокера сообщений.

Исполняется так (через Curl или Guzzle):

```
/bitrix/services/main/ajax.php?mode=ajax&c=wc:debug1c&action=silence&type=catalog&work=import&login=admin&password=admin

```

Как-то так:

```php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Runner
{
    /**
     * @var Client $client Guzzle.
     */
    private $client;

    /**
     * @var string $login Логин.
     */
    private $login;

    /**
     * @var string $password Пароль.
     */
    private $password;

    /**
     * @param Client $client   Guzzle.
     * @param string $login    Пароль.
     * @param string $password Логин.
     */
    public function __construct(
        Client $client,
        string $login,
        string $password
    ) {
        $this->client = $client;
        $this->login = $login;
        $this->password = $password;
    }

    public function run(array $paramMessage = []): bool
    {
        // /bitrix/services/main/ajax.php?mode=ajax&c=wc:debug1c&action=init&silence=Y&type=catalog&work=import&login=admin&password=admin

        $permanentParams = [
            'mode' => 'ajax',
            'c'=> 'wc:debug1c',
            'action' => 'silence',
            'login' => $this->login,
            'password' => $this->password,
        ];

        $params = $this->prepareParameters($paramMessage);
        $params = array_merge($permanentParams, $params);

        // В CLI режиме HTTP_HOST пуст. Предполагается, что в $_ENV['DOMAIN'] лежит то, что надо.
        $url = $_ENV['DOMAIN'] . '/bitrix/services/main/ajax.php';

        // Инициализация
        $prepareResult = $this->callBitrixExchangeEndpoint(
            $url,
            [
                'mode' => 'ajax',
                'c'=> 'wc:debug1c',
                'action' => 'prepare',
            ]
        );

        if ((string)$prepareResult['status'] !== 'success') {
            return false;
        }

        // Обмен
        $importResult = $this->callBitrixExchangeEndpoint(
            $url,
            $params
        );

        if ((string)$importResult['status'] !== 'success') {
            return false;
        }

        return true;
    }

    /**
     * @param string $url    URL.
     * @param array  $params Параметры.
     *
     * @return array
     * @throws GuzzleException
     */
    private function callBitrixExchangeEndpoint(string $url, array $params) : array
    {
        $result = $this->client->get(
            $url,
            ['query' => $params, 'connect_timeout' => 6000]
        );

        return (array)json_decode($result->getBody()->getContents(), true);
    }

    /**
     * @param array $params Параметры.
     *
     * @return array
     */
    private function prepareParameters(array $params) : array
    {
        if (!array_key_exists('exchange', $params)) {
            $params['exchange'] = '/bitrix/admin/1c_exchange.php';
        }

        if (!array_key_exists('type', $params)) {
            $params['type'] = 'catalog';
        }

        if (!array_key_exists('work', $params)) {
            $params['work'] = 'import';
        }

        return $params;
    }
}
```

Параметры:

- `type` - тип импорта (`catalog`, `sale` и т.п.)
- `work` - режим импорта (аналог оригинальному `mode` - `import`, `query`, `info` и т.д.)
- `login` - логин к Битриксу
- `password` - пароль к Битриксу
- `timelimit` - максимальное время исполнения скрипта (опционально)

### Установка
* Компонент распаковать в /local/components/wc/debug1c/
* Разместить компонент на любой странице (Путь в визуальном редакторе WC/Debug1C)
* Указать в параметрах компонента логин\пароль для http клиента дебага
* Ограничение доступа можно настроить через .access.php

### Режимы
* Catalog Import - загрузка каталога в ИБ (/upload/1c_catalog/*)
* Sale Import - загрузка заказов (/upload/1c_exchange/*)
* HighLoadBlock Import - загрузка справочников в HL блоки (/upload/1c_highloadblock/*)
* Exchange Order - пометить заказ на выгрузку в 1С
* Sale Query - выгрузка заказов помеченных для обмена с 1С (при выгрузке по ИД пометка игнорируется**)
* Sale Info - информация о настройках обмена


### Поддерживаемые компоненты обмена
* sale.export.1c (Sale Import, Exchange Order, Sale Query, Sale Info)
* catalog.import.1c (Catalog Import)
* catalog.import.hl (HighLoadBlock Import)

### Системные требования
* 1C-Битрикс: 20.200.300 (на более старых не проверено)
* Кодировка: UTF-8

### Примечания
*Каталоги в которых должны располагаться .xml файлы соответственно. Название любое, используется 1 найденный файл. .xml для загрузки можно получить выгрузкой в папку с помощью соответствующего модуля обмена 1С.

**Для выгрузки по ИД заказа (Sale Query) в компонент sale.export.1c component.php  (300+ строка)

перед строкой
```
if($_SESSION["BX_CML2_EXPORT"]["cmlVersion"] >= doubleval(\Bitrix\Sale\Exchange\ExportOneCBase::SHEM_VERSION_2_10))
```
добавить
```
if ($_GET['orderId'] > 0) {
    unset($arFilter);
    $arFilter["ID"] = (int)$_GET['orderId'];
}
```
