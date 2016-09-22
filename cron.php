<?php
	include(dirname(__FILE__).'/../../config/config.inc.php');
	include(dirname(__FILE__).'/../../init.php');

	if($m = Module::getInstanceByName('automater')) {
		if ($m->isApiActive()) {
			$m->cronjobimportProduct();
		}
	}

	echo('ok');
?>
