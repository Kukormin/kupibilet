<?

// Константы
require('const.php');

// Функции
require('functions.php');

// Библиотеки композера
require(dirname(__FILE__) . '/../vendor/autoload.php');

// Обработчики событий
\Local\System\Handlers::addEventHandlers();

// Модули битрикса
\Bitrix\Main\Loader::IncludeModule('iblock');
\Bitrix\Main\Loader::IncludeModule('highloadblock');

define('ROOT_DIR', $_SERVER['DOCUMENT_ROOT']);
require_once ROOT_DIR . "/Funcs.php";