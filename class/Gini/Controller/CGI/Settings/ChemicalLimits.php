<?php

namespace Gini\Controller\CGI\Settings;

use \Gini\Controller\CGI\Layout\Common;

class ChemicalLimits extends Common {

    public function __index() {
        $me = _G('ME');
        $group = _G('GROUP');

        $form = $this->form();

        // if (!$me->isAllowedTo('设置')) {
        //     $this->redirect('error/401');
        // }
        //
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        }

        $rpc = \Gini\Module\HazardousControlOrders::getRPC('hazardous-control');
        $limits = $rpc->Admin->Inventory->getGroupLimits($group->id);
        $vars = [
            'form' => $form,
            'limits' => $limits,
        ];

        $this->view->body = V('settings/chemical-limits', $vars);
    }

}