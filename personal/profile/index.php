<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Настройки пользователя");
?>
<div class="engBox">
	<div class="elFormAuth">
	<?$APPLICATION->IncludeComponent(
		"bitrix:main.profile",
		"",
		array(
			"SET_TITLE" => "Y",
			"COMPONENT_TEMPLATE" => "my_cabinet",
			"AJAX_MODE" => "N",
			"AJAX_OPTION_JUMP" => "N",
			"AJAX_OPTION_STYLE" => "Y",
			"AJAX_OPTION_HISTORY" => "N",
			"AJAX_OPTION_ADDITIONAL" => "",
			"USER_PROPERTY" => array(
			),
			"SEND_INFO" => "N",
			"CHECK_RIGHTS" => "N",
			"USER_PROPERTY_NAME" => ""
		),
		false
	);?>
	</div>
</div>

<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>
