<?php

namespace Gini\Controller\CGI\AJAX\Settings;

class ChemicalLimits extends \Gini\Controller\CGI
{
    public function actionRequestsMore($page=1)
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id || !$me->isAllowedTo('设置')) return;

        $rpc = \Gini\Module\HazardousControlOrders::getRPC('chemical-limits');
        $result = $rpc->admin->inventory->searchGroupRequests([
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
            'total'=> ceil($count / $perpage)
        ]));
    }

    public function actionGetRequestModal()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id || !$me->isAllowedTo('设置')) return;

        $types = \Gini\Module\HazardousControlOrders::getChemicalTypes();
        $view = 'settings/chemical-limits-request-modal';

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V($view, [
            'types' => $types,
        ]));
    }

    public function actionSearchChemical()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id || !$me->isAllowedTo('设置')) return;

        $form = $this->form('post');
        if (empty($form) || !($q = trim($form['q'])) || !($type = trim($form['type']))) {
            return \Gini\IoC::construct('\Gini\CGI\Response\JSON', []);
        }
    }

    public function actionSubmitApplication()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id || !$me->isAllowedTo('设置')) return;

        $form = $this->form('post');
        $type = trim($form['type']);
        $casNO = trim($form['cas_no']);
        $volume = trim($form['volume']);

        $rpc = \Gini\Module\HazardousControlOrders::getRPC('chemical-limits');
        $result = (bool) $rpc->admin->inventory->addRequest([
            'type'=> $type,
            'cas_no'=> $casNO,
            'volume'=> $volume,
            'group_id'=> $group->id,
            'owner_id'=> $me->id
        ]);

        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', [
            'code'=> $result ? 1 : 0,
            'message'=> $result ? T('提交上限申请失败，请重试') : T('上限申请提交成功')
        ]);
    }
}