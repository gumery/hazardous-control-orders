<?php

namespace Gini\Controller\CLI\HazardousControl;

class Inventory extends \Gini\Controller\CLI
{
    private static $rpc;
    private function getRPC()
    {
        if (self::$rpc) return self::$rpc;

        $confs = \Gini\Config::get('app.rpc');
        $conf = (array)$confs['lab-inventory'];
        $url = $conf['url'];
        $client = \Gini\Config::get('app.client');
        $clientID = $client['id'];
        $clientSecret = $client['secret'];
        $rpc = \Gini\IoC::construct('\Gini\RPC', $url);
        if (!$rpc->mall->authorize($clientID, $clientSecret)) {
            return;
        }
        self::$rpc = $rpc;
        return $rpc;
    }

    public function actionCheckRemoteExists()
    {
        $dtstart =  date('Y-m-d', strtotime("-3 days"));
        $dtend = date('Y-m-d');

        $start = 0;
        $limit = 20;
        $conf = \Gini\Config::get('app.rpc')['chemdb'];
        $rpc1 = \Gini\IoC::construct('\Gini\RPC', $conf['url']);
        $rpc2 = self::getRPC('lab-inventory');
        while(true) {
            $orders = Those('order')->whose('customized')->is(false)
            ->andWhose('ctime')->isGreaterThan($dtstart)
            ->andWhose('ctime')->isLessThan($dtend)
            ->limit($start, $limit);
            if (!count($orders)) break;
            $start += $limit;
            $sync_inv = true;
            foreach ($orders as $order) {
                $group = $order->group;
                $items = $order->items;
                foreach ($items as $item) {
                    $pid     = $item['id'];
                    $product = a('product', $pid);
                    $cas_no  = $product->cas_no;
                    $type    = $product->type;

                    if ($type == 'chem_reagent' && $cas_no) {
                        $results = $rpc1->chemDB->getChemicalTypes($cas_no);
                        if (isset($results[$cas_no])) {
                            $result = $results[$cas_no];
                            /*
                                $result : '100-19-2'=>[hazardous, highly_toxic]
                            */
                            $hazs = ['drug_precursor','highly_toxic',
                            'hazardous','explosive'];
                            if (count(array_intersect($hazs, $result))) {
                                $criteria                  = [];
                                $criteria['order_voucher'] = $order->voucher;
                                $criteria['product']       = $pid;
                                $data = $rpc2->mall->inventory->getInventory($criteria);
                                if (!isset($data['id'])) {
                                    echo "no data\n";
                                    // 危化品没有生成对应的存货记录, 重新生成
                                    $product = a('product', $pid);
                                    $data = [
                                        'name' => $product->name,
                                        'manufacturer' => $product->manufacturer,
                                        'brand' => $product->brand,
                                        'catalog_no' => $product->catalog_no,
                                        'package' => $product->package,
                                        'price' => $item['unit_price'],
                                        'quantity' => $item['quantity'],
                                        'user' => $order->requester->id,
                                        'group' => $order->group->id,
                                        'product' => $pid,
                                        'order_voucher' => $order->voucher,
                                        'vendor_id' => $order->vendor->id,
                                        'vendor_name' => $order->vendor->name,
                                        'location'=>'--',
                                    ];
                                    $rpc2->mall->inventory->createItem($data);
                                }
                                else {
                                    echo "has data\n";
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}