<?php

namespace Gini\Controller\CGI\AJAX\Settings;

class ChemicalLimits extends \Gini\Controller\CGI
{
    public function actionRequestsMore($page=1)
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id && !$group->id) return;

        $rpc = \Gini\Module\HazardousControlOrders::getRPC('chemical-limits');
        $result = $rpc->admin->inventory->searchGroupRequest([
            'group_id'=> $group->id
        ]);
        $token = $result['token'];
        $count = $result['count'];

        $perpage = 25;
        $start = max(($page-1) * $perpage, 0);
        if ($token) {
            $requests = $rpc->admin->inventory->getGroupRequests($token, $start, $perpage);
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('settings/chemical-limits-requests', [
            'requests' => (array)$requests,
            'page'=> $page,
            'total'=> ceil($count / $perpage) + 1
        ]));
    }

    public function actionGetRequestModal()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id && !$group->id) {
            return false;
        }

        $types = \Gini\Module\HazardousControlOrders::getChemicalTypes();
        $view = 'settings/chemical-limits-request-modal';

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V($view, [
            'types' => $types,
        ]));
    }

    public function actionSearchChemical()
    {
        $me = _G('ME');
        if (!$me->id) {
            return;
        }

        $group = _G('GROUP');
        if (!$group->id) {
            return;
        }

        $form = $this->form('post');
        if (empty($form) || !($q = trim($form['q'])) || !($type = trim($form['type']))) {
            return \Gini\IoC::construct('\Gini\CGI\Response\JSON', []);
        }
    }

    public function actionSubmitApplication()
    {
        $me = _G('ME');
        if (!$me->id) {
            return;
        }

        $group = _G('GROUP');
        if (!$group->id) {
            return;
        }

        $form = $this->form('post');

        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', true);
        // return \Gini\IoC::construct('\Gini\CGI\Response\JSON' ,[
        // 		'code' => 1,
        // 		'message' => 'success'
        // 	]);
    }
}
