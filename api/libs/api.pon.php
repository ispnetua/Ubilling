<?php

class PONizer {

    protected $allOnu = array();
    protected $allModelsData = array();
    protected $allOltDevices = array();
    protected $allOltSnmp = array();
    protected $allOltModels = array();
    protected $snmpTemplates = array();
    protected $signalCache = array();
    protected $altCfg = array();
    protected $snmp = '';
    protected $sup = '';

    const SIGCACHE_PATH = 'exports/';
    const SIGCACHE_EXT = 'OLTSIGNALS';
    const SNMPCACHE = false;
    const SNMPPORT = 161;
    const ONUSIG_PATH = 'content/documents/onusig/';

    public function __construct() {
        $this->loadAlter();
        $this->loadOltDevices();
        $this->loadOltModels();
        $this->loadSnmpTemplates();
        $this->initSNMP();
        $this->loadOnu();
        $this->loadModels();
        $this->sup = wf_tag('sup') . '*' . wf_tag('sup', true);
    }

    /**
     * Loads system alter.ini config into private data property
     * 
     * @global object $ubillingConfig
     * 
     * @return void
     */
    protected function loadAlter() {
        global $ubillingConfig;
        $this->altCfg = $ubillingConfig->getAlter();
    }

    /**
     * Loads all available devices set as OLT
     * 
     * @return void
     */
    protected function loadOltDevices() {
        $query = "SELECT `id`,`ip`,`location`,`snmp`,`modelid` from `switches` WHERE `desc` LIKE '%OLT%';";
        $raw = simple_queryall($query);
        if (!empty($raw)) {
            foreach ($raw as $io => $each) {
                $this->allOltDevices[$each['id']] = $each['ip'] . ' - ' . $each['location'];
                if (!empty($each['snmp'])) {
                    $this->allOltSnmp[$each['id']]['community'] = $each['snmp'];
                    $this->allOltSnmp[$each['id']]['modelid'] = $each['modelid'];
                    $this->allOltSnmp[$each['id']]['ip'] = $each['ip'];
                }
            }
        }
    }

    /**
     * Loads all available snmp models data into private data property
     * 
     * @return void
     */
    protected function loadOltModels() {
        $rawModels = zb_SwitchModelsGetAll();
        foreach ($rawModels as $io => $each) {
            $this->allOltModels[$each['id']]['modelname'] = $each['modelname'];
            $this->allOltModels[$each['id']]['snmptemplate'] = $each['snmptemplate'];
        }
    }

    /**
     * Performs snmp templates preprocessing for OLT devices
     * 
     * @return void
     */
    protected function loadSnmpTemplates() {
        if (!empty($this->allOltDevices)) {
            foreach ($this->allOltDevices as $oltId => $eachOltData) {
                if (isset($this->allOltSnmp[$oltId])) {
                    $oltModelid = $this->allOltSnmp[$oltId]['modelid'];
                    if ($oltModelid) {
                        if (isset($this->allOltModels[$oltModelid])) {
                            $templateFile = 'config/snmptemplates/' . $this->allOltModels[$oltModelid]['snmptemplate'];
                            if (file_exists($templateFile)) {
                                $this->snmpTemplates[$oltModelid] = rcms_parse_ini_file($templateFile, true);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Creates single instance of SNMPHelper object
     * 
     * @return void
     */
    protected function initSNMP() {
        $this->snmp = new SNMPHelper();
    }

    /**
     * Performs signal preprocessing for sig/mac index arrays and stores it into cache
     * 
     * @param int   $oltid
     * @param array $sigIndex
     * @param array $macIndex
     * @param array $snmpTemplate
     * 
     * @return void
     */
    protected function signalParseBd($oltid, $sigIndex, $macIndex, $snmpTemplate) {
        $oltid = vf($oltid, 3);
        $sigTmp = array();
        $macTmp = array();
        $result = array();
        $curDate = curdatetime();

        //signal index preprocessing
        if ((!empty($sigIndex)) AND ( !empty($macIndex))) {
            foreach ($sigIndex as $io => $eachsig) {
                $line = explode('=', $eachsig);
                //signal is present
                if (isset($line[1])) {
                    $signalRaw = trim($line[1]); // signal level
                    $devIndex = trim($line[0]); // device index
                    if ($signalRaw == $snmpTemplate['DOWNVALUE']) {
                        $signalRaw = 'Offline';
                    } else {
                        if ($snmpTemplate['OFFSETMODE'] == 'div') {
                            if ($snmpTemplate['OFFSET']) {
                                $signalRaw = $signalRaw / $snmpTemplate['OFFSET'];
                            }
                        }
                    }
                    $sigTmp[$devIndex] = $signalRaw;
                }
            }

            //mac index preprocessing
            foreach ($macIndex as $io => $eachmac) {
                $line = explode('=', $eachmac);
                //mac is present
                if (isset($line[1])) {
                    $macRaw = trim($line[1]); //mac address
                    $devIndex = trim($line[0]); //device index
                    $macRaw = str_replace(' ', ':', $macRaw);
                    $macRaw = strtolower($macRaw);
                    $macTmp[$devIndex] = $macRaw;
                }
            }

            //storing results
            if (!empty($macTmp)) {
                foreach ($macTmp as $devId => $eachMac) {
                    if (isset($sigTmp[$devId])) {
                        $signal = $sigTmp[$devId];
                        $result[$eachMac] = $signal;
                        //signal history filling
                        $historyFile = self::ONUSIG_PATH . md5($eachMac);
                        if ($signal=='Offline') {
                            $signal=-9000; //over 9000 offline signal level :P
                        }
                        file_put_contents($historyFile, $curDate . ',' . $signal . "\n", FILE_APPEND);
                    }
                }
                $result = serialize($result);
                file_put_contents(self::SIGCACHE_PATH . $oltid . '_' . self::SIGCACHE_EXT, $result);
            }
        }
    }

    /**
     * Performs  OLT device polling with snmp
     * 
     * @param int $oltid
     * 
     * @return void
     */
    protected function pollOltSignal($oltid) {
        $oltid = vf($oltid, 3);
        if (isset($this->allOltDevices[$oltid])) {
            if (isset($this->allOltSnmp[$oltid])) {
                $oltCommunity = $this->allOltSnmp[$oltid]['community'];
                $oltModelId = $this->allOltSnmp[$oltid]['modelid'];
                $oltIp = $this->allOltSnmp[$oltid]['ip'];
                if (isset($this->snmpTemplates[$oltModelId])) {
                    if (isset($this->snmpTemplates[$oltModelId]['signal'])) {
                        // BDCOM/Eltex devices polling
                        if ($this->snmpTemplates[$oltModelId]['signal']['SIGNALMODE'] == 'BDCOM') {
                            $sigIndexOID = $this->snmpTemplates[$oltModelId]['signal']['SIGINDEX'];
                            $sigIndex = $this->snmp->walk($oltIp . ':' . self::SNMPPORT, $oltCommunity, $sigIndexOID, self::SNMPCACHE);
                            $sigIndex = str_replace($sigIndexOID . '.', '', $sigIndex);
                            $sigIndex = str_replace($this->snmpTemplates[$oltModelId]['signal']['SIGVALUE'], '', $sigIndex);
                            $sigIndex = explodeRows($sigIndex);

                            $macIndexOID = $this->snmpTemplates[$oltModelId]['signal']['MACINDEX'];
                            $macIndex = $this->snmp->walk($oltIp . ':' . self::SNMPPORT, $oltCommunity, $macIndexOID, self::SNMPCACHE);
                            $macIndex = str_replace($macIndexOID . '.', '', $macIndex);
                            $macIndex = str_replace($this->snmpTemplates[$oltModelId]['signal']['MACVALUE'], '', $macIndex);
                            $macIndex = explodeRows($macIndex);
                            $this->signalParseBd($oltid, $sigIndex, $macIndex, $this->snmpTemplates[$oltModelId]['signal']);
                        }
                    }
                }
            }
        }
    }

    /**
     * Performs available OLT devices polling. Use only in remote API.
     * 
     * @return void
     */
    public function oltDevicesPolling() {
        if (!empty($this->allOltDevices)) {
            foreach ($this->allOltDevices as $oltid => $each) {
                print('POLLING:' . $oltid . ' ' . $each . "\n");
                $this->pollOltSignal($oltid);
            }
        }
    }

    /**
     * Loads avaliable ONUs from database into private data property
     * 
     * @return void
     */
    protected function loadOnu() {
        $query = "SELECT * from `pononu`";
        $all = simple_queryall($query);
        if (!empty($all)) {
            foreach ($all as $io => $each) {
                $this->allOnu[$each['id']] = $each;
            }
        }
    }

    /**
     * Getter for loaded ONU devices
     * 
     * @return array
     */
    public function getAllOnu() {
        return ($this->allOnu);
    }

    /**
     * Loads available device models from database
     * 
     * @return void
     */
    protected function loadModels() {
        $tmpModels = zb_SwitchModelsGetAll();
        if (!empty($tmpModels)) {
            foreach ($tmpModels as $io => $each) {
                $this->allModelsData[$each['id']] = $each;
            }
        }
    }

    /**
     * Returns model name by its id
     * 
     * @param int $id
     * @return string
     */
    protected function getModelName($id) {
        $result = '';
        if (isset($this->allModelsData[$id])) {
            $result = $this->allModelsData[$id]['modelname'];
        }
        return ($result);
    }

    /**
     * Creates new ONU in database and returns it Id or 0 if action fails
     * 
     * @param int $onumodelid
     * @param int $oltid
     * @param string $ip
     * @param string $mac
     * @param string $serial
     * @param string $login
     * 
     * @return int
     */
    public function onuCreate($onumodelid, $oltid, $ip, $mac, $serial, $login) {
        $mac = strtolower($mac);
        $mac = trim($mac);
        $onumodelid = vf($onumodelid, 3);
        $oltid = vf($oltid, 3);
        $ip = mysql_real_escape_string($ip);
        $macRaw = $mac;
        $mac = mysql_real_escape_string($mac);
        $serial = mysql_real_escape_string($serial);
        $login = mysql_real_escape_string($login);
        $result = 0;
        if (!empty($mac)) {
            if (check_mac_format($mac)) {
                $query = "INSERT INTO `pononu` (`id`, `onumodelid`, `oltid`, `ip`, `mac`, `serial`, `login`) "
                        . "VALUES (NULL, '" . $onumodelid . "', '" . $oltid . "', '" . $ip . "', '" . $mac . "', '" . $serial . "', '" . $login . "');";
                nr_query($query);
                $result = simple_get_lastid('pononu');
                log_register('PON CREATE ONU [' . $result . '] MAC `' . $macRaw . '`');
            } else {
                log_register('PON MACINVALID TRY `' . $macRaw . '`');
            }
        }
        return ($result);
    }

    /**
     * Saves ONU changes into database
     * 
     * @param int $onuId
     * @param int $onumodelid
     * @param int $oltid
     * @param string $ip
     * @param string $mac
     * @param string $serial
     * @param string $login
     * 
     * @return void
     */
    public function onuSave($onuId, $onumodelid, $oltid, $ip, $mac, $serial, $login) {
        $mac = strtolower($mac);
        $mac = trim($mac);
        $onuId = vf($onuId, 3);
        $onumodelid = vf($onumodelid, 3);
        $oltid = vf($oltid, 3);
        $ip = mysql_real_escape_string($ip);
        $mac = mysql_real_escape_string($mac);
        $serial = mysql_real_escape_string($serial);
        $login = mysql_real_escape_string($login);
        $where = " WHERE `id`='" . $onuId . "';";
        simple_update_field('pononu', 'onumodelid', $onumodelid, $where);
        simple_update_field('pononu', 'oltid', $oltid, $where);
        simple_update_field('pononu', 'ip', $ip, $where);
        if (!empty($mac)) {
            if (check_mac_format($mac)) {
                simple_update_field('pononu', 'mac', $mac, $where);
            } else {
                log_register('PON MACINVALID TRY `' . $mac . '`');
            }
        } else {
            log_register('PON MACEMPTY TRY `' . $mac . '`');
        }
        simple_update_field('pononu', 'serial', $serial, $where);
        simple_update_field('pononu', 'login', $login, $where);
        log_register('PON EDIT ONU [' . $onuId . ']');
    }

    /**
     * Deletes onu from database by its ID
     * 
     * @param int $onuId
     */
    public function onuDelete($onuId) {
        $onuId = vf($onuId, 3);
        $query = "DELETE from `pononu` WHERE `id`='" . $onuId . "';";
        nr_query($query);
        log_register('PON DELETE ONU [' . $onuId . ']');
    }

    /**
     * Returns ONU creation form
     * 
     * @return string
     */
    protected function onuCreateForm() {
        $models = array();
        if (!empty($this->allModelsData)) {
            foreach ($this->allModelsData as $io => $each) {
                $models[$each['id']] = $each['modelname'];
            }
        }

        $inputs = wf_HiddenInput('createnewonu', 'true');
        $inputs.= wf_Selector('newoltid', $this->allOltDevices, __('OLT device') . $this->sup, '', true);
        $inputs.= wf_Selector('newonumodelid', $models, __('ONU model') . $this->sup, '', true);
        $inputs.= wf_TextInput('newip', __('IP'), '', true, 20);
        $inputs.= wf_TextInput('newmac', __('MAC') . $this->sup, '', true, 20);
        $inputs.= wf_TextInput('newserial', __('Serial number'), '', true, 20);
        $inputs.= wf_TextInput('newlogin', __('Login'), '', true, 20);
        $inputs.= wf_Submit(__('Create'));

        $result = wf_Form('', 'POST', $inputs, 'glamour');
        return ($result);
    }

    /**
     * returns vendor by MAC search control if this enabled in config
     * 
     * @return string
     */
    protected function getSearchmacControl($mac) {
        $result = '';
        if ($this->altCfg['MACVEN_ENABLED']) {
            if (!empty($mac)) {
                $vendorframe = wf_tag('iframe', false, '', 'src="?module=macvendor&mac=' . $mac . '" width="360" height="160" frameborder="0"');
                $vendorframe.= wf_tag('iframe', true);
                $result = wf_modalAuto(wf_img('skins/macven.gif', __('Device vendor')), __('Device vendor'), $vendorframe, '');
            }
        }
        return ($result);
    }

    /**
     * Returns ONU edit form
     * 
     * @param int $onuId
     * 
     * @return string
     */
    public function onuEditForm($onuId) {
        $onuId = vf($onuId, 3);
        $result = '';
        if (isset($this->allOnu[$onuId])) {
            $messages = new UbillingMessageHelper();

            $models = array();
            if (!empty($this->allModelsData)) {
                foreach ($this->allModelsData as $io => $each) {
                    $models[$each['id']] = $each['modelname'];
                }
            }

            $inputs = wf_HiddenInput('editonu', $onuId);
            $inputs.= wf_Selector('editoltid', $this->allOltDevices, __('OLT device') . $this->sup, $this->allOnu[$onuId]['oltid'], true);
            $inputs.= wf_Selector('editonumodelid', $models, __('ONU model') . $this->sup, $this->allOnu[$onuId]['onumodelid'], true);
            $inputs.= wf_TextInput('editip', __('IP'), $this->allOnu[$onuId]['ip'], true, 20);
            $inputs.= wf_TextInput('editmac', __('MAC') . $this->sup.' '.$this->getSearchmacControl($this->allOnu[$onuId]['mac']), $this->allOnu[$onuId]['mac'], true, 20);
            $inputs.= wf_TextInput('editserial', __('Serial number'), $this->allOnu[$onuId]['serial'], true, 20);
            $inputs.= wf_TextInput('editlogin', __('Login'), $this->allOnu[$onuId]['login'], true, 20);
            $inputs.= wf_Submit(__('Save'));


            $result = wf_Form('', 'POST', $inputs, 'glamour');
            $result.= wf_CleanDiv();
            $result.= wf_delimiter();

            $result.= wf_Link('?module=ponizer', __('Back'), false, 'ubButton');
            $result.= wf_JSAlertStyled('?module=ponizer&deleteonu=' . $onuId, web_delete_icon() . ' ' . __('Delete'), $messages->getDeleteAlert(), 'ubButton');
        } else {
            $result = wf_tag('div', false, 'alert_error') . __('Strange exeption') . ': ONUID_NOT_EXISTS' . wf_tag('div', true);
        }

        //Signal history chart
        $result.=$this->onuSignalHistory($onuId);

        //additional comments handling
        if ($this->altCfg['ADCOMMENTS_ENABLED']) {
            $adcomments = new ADcomments('PONONU');
            $result.=wf_delimiter();
            $result.=wf_tag('h3') . __('Additional comments') . wf_tag('h3', true);
            $result.=$adcomments->renderComments($onuId);
        }

        return ($result);
    }

    /**
     * Renders ONU signal history chart
     * 
     * @param int $onuId
     * @return string
     */
    protected function onuSignalHistory($onuId) {
        $onuId = vf($onuId, 3);
        $result = '';
        if (isset($this->allOnu[$onuId])) {
            //not empty MAC
            if ($this->allOnu[$onuId]['mac']) {
                $historyKey = self::ONUSIG_PATH . md5($this->allOnu[$onuId]['mac']);
                if (file_exists($historyKey)) {
                    $rawData = file_get_contents($historyKey);
                    $result.=wf_delimiter();
                    $result.= wf_tag('h2') . __('ONU signal history') . wf_tag('h2', true);
                    $result.= wf_tag('div', false, '', '');
                    $result.= wf_Graph($rawData, '800', '300', false) . wf_tag('div', true);
                }
            }
        }
        return ($result);
    }

    /**
     * Returns default list controls
     * 
     * @return string
     */
    public function controls() {
        $result = '';

        $result.=wf_modalAuto(wf_img('skins/add_icon.png') . ' ' . __('Create'), __('Create'), $this->onuCreateForm(), 'ubButton');
        $result.=wf_delimiter();
        return ($result);
    }

    /**
     * Renders available ONU JQDT list container
     * 
     * @return string
     */
    public function renderOnuList() {
        $columns = array('ID', 'Model', 'OLT', 'IP', 'MAC', 'Signal', 'Serial number', 'Login', 'Actions');
        $result = wf_JqDtLoader($columns, '?module=ponizer&ajaxonu=true', false, 'ONU');
        return ($result);
    }

    /**
     * Loads existing signal cache from FS
     * 
     * @return void
     */
    protected function loadSignalsCache() {
        $availCacheData = rcms_scandir(self::SIGCACHE_PATH, '*_' . self::SIGCACHE_EXT);
        if (!empty($availCacheData)) {
            foreach ($availCacheData as $io => $each) {
                $raw = file_get_contents(self::SIGCACHE_PATH . $each);
                $raw = unserialize($raw);
                foreach ($raw as $mac => $signal) {
                    $this->signalCache[$mac] = $signal;
                }
            }
        }
    }

    /**
     * Renders json formatted data for jquery data tables list
     * 
     * @return string
     */
    public function ajaxOnuData() {
        if ($this->altCfg['ADCOMMENTS_ENABLED']) {
            $adcomments = new ADcomments('PONONU');
            $adc = true;
        } else {
            $adc = false;
        }

        $this->loadSignalsCache();

        $result = '{ 
                  "aaData": [ ';

        if (!empty($this->allOnu)) {
            foreach ($this->allOnu as $io => $each) {
                if (!empty($each['login'])) {
                    $userLink = wf_Link('?module=userprofile&username=' . $each['login'], web_profile_icon() . ' ' . $each['login'], false);
                    $userLink = str_replace('"', '', $userLink);
                    $userLink = trim($userLink);
                } else {
                    $userLink = '';
                }
                //checking adcomments availability
                if ($adc) {
                    $indicatorIcon = $adcomments->getCommentsIndicator($each['id']);
                    $indicatorIcon = str_replace('"', '\'', $indicatorIcon);
                    $indicatorIcon = trim($indicatorIcon);
                } else {
                    $indicatorIcon = '';
                }

                $actLinks = wf_Link('?module=ponizer&editonu=' . $each['id'], web_edit_icon(), false);
                $actLinks = str_replace('"', '', $actLinks);
                $actLinks = trim($actLinks);
                $actLinks.= ' ' . $indicatorIcon;

                if (isset($this->signalCache[$each['mac']])) {
                    $signal = $this->signalCache[$each['mac']];
                } else {
                    $signal = __('No');
                }

                $result.='
                    [
                    "' . $each['id'] . '",
                    "' . $this->getModelName($each['onumodelid']) . '",
                    "' . @$this->allOltDevices[$each['oltid']] . '",
                    "' . $each['ip'] . '",
                    "' . $each['mac'] . '",
                    "' . $signal . '",
                    "' . $each['serial'] . '",
                    "' . $userLink . '",
                    "' . $actLinks . '"
                    ],';
            }
        }

        $result = substr($result, 0, -1);

        $result.='] 
        }';

        return ($result);
    }

}

?>