<?php

set_time_limit(0);

if (cfr('SWITCHPOLL')) {

    /**
     * Returns FDB cache lister MAC filters setup form
     * 
     * @param string $currentFilters
     * 
     * @return string
     */
    function web_FDBTableFiltersForm($currentFilters) {
        if (!empty($currentFilters)) {
            $currentFilters = base64_decode($currentFilters);
        }

        $inputs = __('One MAC address per line') . wf_tag('br');
        $inputs .= wf_TextArea('newmacfilters', '', $currentFilters, true, '40x10');
        $inputs .= wf_HiddenInput('setmacfilters', 'true');
        $inputs .= wf_CheckInput('deletemacfilters', __('Cleanup'), true, false);
        $inputs .= wf_Submit(__('Save'));
        $result = wf_Form('', 'POST', $inputs, 'glamour');

        return ($result);
    }

    /**
     * Renders swpoll logs control
     * 
     * @global object $ubillingConfig
     * 
     * @return string
     */
    function web_FDBTableLogControl() {
        global $ubillingConfig;
        $messages = new UbillingMessageHelper();
        $result = '';
        $logPath = 'exports/swpolldata.log';
        $logData = array();
        $renderData = '';
        $rows = '';
        $recordsLimit = 200;
        $prevTime = '';
        $curTimeTime = '';
        $diffTime = '';

        if (file_exists($logPath)) {
            $billCfg = $ubillingConfig->getBilling();
            $tailCmd = $billCfg['TAIL'];
            $runCmd = $tailCmd . ' -n ' . $recordsLimit . ' ' . $logPath;
            $rawResult = shell_exec($runCmd);
            $renderData .= __('Showing') . ' ' . $recordsLimit . ' ' . __('last events') . wf_tag('br');
            $renderData .= wf_Link('?module=switchpoller&dlswpolllog=true', wf_img('skins/icon_download.png', __('Download')) . ' ' . __('Download full log'), true);

            if (!empty($rawResult)) {
                $logData = explodeRows($rawResult);
                if (!empty($logData)) {

                    $cells = wf_TableCell(__('Time') . ' (' . __('seconds') . ')');
                    $cells .= wf_TableCell(__('Date'));
                    $cells .= wf_TableCell(__('IP'));
                    $cells .= wf_TableCell(__('Event'));
                    $rows .= wf_TableRow($cells, 'row1');

                    //  $logData = array_reverse($logData);
                    foreach ($logData as $io => $each) {
                        if (!empty($each)) {
                            if (!ispos($each, 'SWPOLLSTART')) {
                                $eachEntry = explode(' ', $each);
                                $curTime = $eachEntry[0] . ' ' . $eachEntry[1];
                                $curTime = strtotime($curTime);
                                if (!empty($prevTime)) {
                                    $diffTime = $curTime - $prevTime;
                                } else {
                                    $diffTime = 0;
                                }
                                $prevTime = $eachEntry[0] . ' ' . $eachEntry[1];
                                $prevTime = strtotime($prevTime);

                                $cells = wf_TableCell($diffTime);
                                $cells .= wf_TableCell($eachEntry[0] . ' ' . $eachEntry[1]);
                                $cells .= wf_TableCell($eachEntry[2]);
                                $cells .= wf_TableCell($eachEntry[3] . ' ' . @$eachEntry[4] . ' ' . @$eachEntry[5]);
                                $rows .= wf_TableRow($cells, 'row3');
                            } else {
                                $eachEntry = explode(' ', $each);
                                $prevTime = strtotime($eachEntry[0] . ' ' . $eachEntry[1]);
                            }
                        }
                    }
                    $renderData .= wf_TableBody($rows, '100%', 0, 'sortable');
                }
            } else {
                $renderData .= $messages->getStyledMessage(__('Nothing found'), 'warning');
            }

            $result = wf_modal(wf_img('skins/log_icon_small.png', __('Swpoll log')), __('Swpoll log'), $renderData, '', '800', '600');
        }
        return ($result);
    }

    /**
     * Performs downloading of switches polling logs
     * 
     * @return void
     */
    function zb_FDBTableLogDownload() {
        $logPath = 'exports/swpolldata.log';
        if (file_exists($logPath)) {
            zb_DownloadFile($logPath);
        } else {
            show_error(__('Something went wrong') . ': EX_FILE_NOT_FOUND ' . $logPath);
        }
    }

    /**
     * Renders horde stats control if horde enabled
     * 
     * @return string
     */
    function web_HordeStatsControl() {
        global $ubillingConfig;
        $result = '';
        if ($ubillingConfig->getAlterParam('HORDE_OF_SWITCHES')) {
            $stats = '';
            $hordePath = 'exports/';
            $allHordeStats = rcms_scandir($hordePath, '*HORDE_*');
            $totalCount = 0;
            $totalTime = 0;
            if (!empty($allHordeStats)) {
                $cells = wf_TableCell(__('IP'));
                $cells .= wf_TableCell(__('from'));
                $cells .= wf_TableCell(__('to'));
                $cells .= wf_TableCell(__('time'));
                $rows = wf_TableRow($cells, 'row1');
                foreach ($allHordeStats as $io => $eachStat) {
                    $devIp = zb_ExtractIpAddress($eachStat);
                    $statData = file_get_contents($hordePath . $eachStat);
                    if (!empty($statData)) {
                        $statData = unserialize($statData);
                        $pollTime = $statData['end'] - $statData['start'];
                        $cells = wf_TableCell($devIp);
                        $cells .= wf_TableCell(date("Y-m-d H:i:s", $statData['start']));
                        $cells .= wf_TableCell(date("Y-m-d H:i:s", $statData['end']));
                        $cells .= wf_TableCell(zb_formatTime($pollTime));
                        $rows .= wf_TableRow($cells, 'row5');
                        $totalCount++;
                        $totalTime += $pollTime;
                    }
                }
                $stats .= wf_TableBody($rows, '100%', 0, '');
                $stats .= wf_tag('b') . __('Total') . ' ' . __('time') . ': ' . wf_tag('b', true) . zb_formatTime($totalTime) . wf_delimiter(0);
                $stats .= wf_tag('b') . __('Devices') . ': ' . wf_tag('b', true) . $totalCount . wf_delimiter(0);
            } else {
                $messages = new UbillingMessageHelper();
                $stats .= $messages->getStyledMessage(__('Nothing to show'), 'warning');
            }
            $result .= ' ' . wf_modal(wf_img('skins/orc_small.png', __('Devices polling stats')), __('Devices polling stats'), $stats, '', '800', '600');
        }
        return($result);
    }

    /**
     * Shows current FDB cache list container
     * 
     * @param string $fdbSwitchFilter
     */
    function web_FDBTableShowDataTable($fdbSwitchFilter = '', $fdbMacFilter = '') {
        global $ubillingConfig;
        $fdbExtenInfo = $ubillingConfig->getAlterParam('SW_FDB_EXTEN_INFO');
        $filter = '';
        $macfilter = '';
        $result = '';
        $filter = (!empty($fdbSwitchFilter)) ? '&swfilter=' . $fdbSwitchFilter : '';
        $macfilter = (!empty($fdbMacFilter)) ? '&macfilter=' . $fdbMacFilter : '';
        $currentFilters = zb_StorageGet('FDBCACHEMACFILTERS');

        $filtersForm = wf_modalAuto(web_icon_search('MAC filters setup'), __('MAC filters setup'), web_FDBTableFiltersForm($currentFilters), '');
        if (!empty($currentFilters)) {
            $filtersForm .= ' ' . wf_img('skins/filter_icon.png', __('Filters'));
        }
        $logControls = web_FDBTableLogControl();
        $logControls .= web_HordeStatsControl();

        $mainControls = FDBArchive::renderNavigationPanel();
        show_window('', $mainControls);

        if ($fdbExtenInfo) {
            $columns = array('Switch IP', 'Port', __('Port description'), 'VLAN', 'Location', 'MAC', __('User') . ' / ' . __('Device'));
        } else {
            $columns = array('Switch IP', 'Port', 'Location', 'MAC', __('User') . ' / ' . __('Device'));
        }

        $result .= wf_JqDtLoader($columns, '?module=switchpoller&ajax=true' . $filter . $macfilter, true, 'Objects', 100);

        show_window(__('Current FDB cache') . ' ' . $filtersForm . ' ' . $logControls, $result);
    }

    $allDevices = sp_SnmpGetAllDevices();
    $allTemplates = sp_SnmpGetAllModelTemplates();
    $allTemplatesAssoc = sp_SnmpGetModelTemplatesAssoc();
    $allusermacs = zb_UserGetAllMACs();
    $alladdress = zb_AddressGetFullCityaddresslist();
    $alldeadswitches = zb_SwitchesGetAllDead();
    $deathTime = zb_SwitchesGetAllDeathTime();
    if ($ubillingConfig->getAlterParam('SWITCHES_EXTENDED')) {
        $allswitchmacs = array();
        $allSwitches = zb_SwitchesGetAll();
        if (!empty($allSwitches)) {
            foreach ($allSwitches as $io => $each) {
                if (!empty($each['swid'])) {
                    $allswitchmacs[$each['swid']]['id'] = $each['id'];
                    $allswitchmacs[$each['swid']]['ip'] = $each['ip'];
                    $allswitchmacs[$each['swid']]['location'] = $each['location'];
                }
            }
        }
    } else {
        $allswitchmacs = array();
    }

    //poll single device
    if (wf_CheckGet(array('switchid'))) {
        $switchId = vf($_GET['switchid'], 3);
        if (!empty($allDevices)) {
            foreach ($allDevices as $ia => $eachDevice) {
                if ($eachDevice['id'] == $switchId) {
                    //detecting device template
                    if (!empty($allTemplatesAssoc)) {
                        if (isset($allTemplatesAssoc[$eachDevice['modelid']])) {
                            if (!isset($alldeadswitches[$eachDevice['ip']])) {
                                //cache cleanup
                                if (wf_CheckGet(array('forcecache'))) {
                                    $deviceRawSnmpCache = rcms_scandir('./exports/', $eachDevice['ip'] . '_*');
                                    if (!empty($deviceRawSnmpCache)) {
                                        foreach ($deviceRawSnmpCache as $ir => $fileToDelete) {
                                            unlink('./exports/' . $fileToDelete);
                                        }
                                    }
                                    rcms_redirect('?module=switchpoller&switchid=' . $eachDevice['id']);
                                }
                                $deviceTemplate = $allTemplatesAssoc[$eachDevice['modelid']];
                                $modActions = wf_BackLink('?module=switches');
                                $modActions .= wf_Link('?module=switches&edit=' . $switchId, web_edit_icon() . ' ' . __('Edit') . ' ' . __('Switch'), false, 'ubButton');
                                if (cfr('SWITCHSONIC')) {
                                    if ($ubillingConfig->getAlterParam('SWITCHSONIC_ENABLED')) {
                                        if (!empty($eachDevice['snmp'])) {
                                            $ssonicUrl = '?module=switchsonic';
                                            $ssonicUrl .= '&swid=' . $eachDevice['id'];
                                            $ssonicUrl .= '&swip=' . $eachDevice['ip'];
                                            $ssonicUrl .= '&swcomm=' . $eachDevice['snmp'];

                                            $modActions .= wf_Link($ssonicUrl, wf_img('skins/sonic_icon.png') . ' ' . __('Realtime traffic'), false, 'ubButton');
                                        }
                                    }
                                }
                                $modActions .= wf_Link('?module=switchpoller&switchid=' . $eachDevice['id'] . '&forcecache=true', wf_img('skins/refresh.gif') . ' ' . __('Force query'), false, 'ubButton');

                                show_window($deviceTemplate . ' ' . $eachDevice['ip'] . ' - ' . $eachDevice['location'], $modActions);
                                sp_SnmpPollDevice($eachDevice['ip'], $eachDevice['snmp'], $allTemplates, $deviceTemplate, $allusermacs, $alladdress, $eachDevice['snmpwrite'], false, $allswitchmacs);
                            } else {
                                show_error(__('Switch dead since') . ' ' . @$deathTime[$eachDevice['ip']]);
                                show_window('', wf_BackLink('?module=switches') . ' ' . wf_Link('?module=switches&edit=' . $switchId, web_edit_icon() . ' ' . __('Edit switch'), false, 'ubButton'));
                            }
                        } else {
                            show_error(__('No') . ' ' . __('SNMP template'));
                        }
                    }
                }
            }
        }
    } else {


        //display all of available fdb tables
        $fdbData_raw = rcms_scandir('./exports/', '*_fdb');
        if (!empty($fdbData_raw)) {
            //// mac filters setup
            if (wf_CheckPost(array('setmacfilters'))) {
                //setting new MAC filters
                if (!empty($_POST['newmacfilters'])) {
                    $newFilters = base64_encode($_POST['newmacfilters']);
                    zb_StorageSet('FDBCACHEMACFILTERS', $newFilters);
                }
                //deleting old filters
                if (isset($_POST['deletemacfilters'])) {
                    zb_StorageDelete('FDBCACHEMACFILTERS');
                }
            }

            //log download
            if (wf_CheckGet(array('dlswpolllog'))) {
                zb_FDBTableLogDownload();
            }

            //push ajax data
            if (wf_CheckGet(array('ajax'))) {
                if (wf_CheckGet(array('swfilter'))) {
                    $fdbData_raw = array($_GET['swfilter'] . '_fdb');
                }
                if (wf_CheckGet(array('macfilter'))) {
                    $macFilter = $_GET['macfilter'];
                } else {
                    $macFilter = '';
                }
                sn_SnmpParseFdbCacheJson($fdbData_raw, $macFilter);
            } else {
                if (wf_CheckGet(array('fdbfor'))) {
                    $fdbSwitchFilter = $_GET['fdbfor'];
                } else {
                    $fdbSwitchFilter = '';
                }
                if (wf_CheckGet(array('macfilter'))) {
                    $fdbMacFilter = $_GET['macfilter'];
                } else {
                    $fdbMacFilter = '';
                }

                web_FDBTableShowDataTable($fdbSwitchFilter, $fdbMacFilter);
            }
        } else {
            show_warning(__('Nothing found'));
        }
    }
} else {
    show_error(__('Access denied'));
}
?>
