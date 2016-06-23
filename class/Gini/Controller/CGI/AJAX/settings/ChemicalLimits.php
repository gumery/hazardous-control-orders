<?php 

namespace Gini\Controller\CGI\AJAX\Settings;

class ChemicalLimits extends \Gini\Controller\CGI {
	
	public function actionGetRequestModal() {
		$me = _G('ME');
		$group = _G('GROUP');
		if (!$me->id && !$group->id) return false;
		
		$types = \Gini\Module\HazardousControlOrders::getChemicalTypes();
		$view = 'settings/chemical-limits-request-modal';
		return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V($view, [
			'types' => $types,
		]));
	}

	public function actionSearchChemical() {
		$me = _G('ME');
        if (!$me->id) return;

        $group = _G('GROUP');
        if (!$group->id) return;
	
		$form = $this->form('post');
		if (empty($form) || !($q = trim($form['q'])) || !($type = trim($form['type']))) {
			return \Gini\IoC::construct('\Gini\CGI\Response\JSON', []);
		}
			

	}

	public function actionSubmitApplication() {

	}
}
