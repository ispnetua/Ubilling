<?php

/**
 * PhotoStorage allows to attach images for any kind of items on some scope
 */
class PhotoStorage {

    /**
     * Contains system photostorage.ini config as key=>value
     *
     * @var array
     */
    protected $photoCfg = array();

    /**
     * Contains system alter config as key=>value
     *
     * @var array
     */
    protected $altCfg = array();

    /**
     * Contains array of available images in database as id=>imagedata
     *
     * @var array
     */
    protected $allimages = array();

    /**
     * Contains loaded images count for each item in some scope as scope=>itemid=>count
     *
     * @var array
     */
    protected $imagesCount = array();

    /**
     * Contains current photostorage items scope
     *
     * @var string
     */
    protected $scope = '';

    /**
     * Contains current instance item ID in the current scope
     *
     * @var string
     */
    protected $itemId = '';

    /**
     * Contains current administrator login
     *
     * @var string
     */
    protected $myLogin = '';

    /**
     * Flag for preventing multiple database requests
     *
     * @var bool
     */
    protected $imagesLoadedFlag = false;

    /**
     * Use photostorage as image proxy flag
     *
     * @var bool
     */
    protected $proxyMode = false;

    /**
     * Contains images storage path. May be specified in PHOTOSTORAGE_DIRECTORY option.
     *
     * @var string
     */
    protected $storagePath = 'content/documents/photostorage/';

    /**
     * Custom, optional images display prefix URL. Configurable via PHOTOSTORAGE_URL_PREFIX option.
     *
     * @var string
     */
    protected $storageUrlPrefix = '';

    /**
     * Some predefined paths and URLs
     */
    const UPLOAD_URL_WEBC = '?module=photostorage&uploadcamphoto=true';
    const UPLOAD_URL_FILE = '?module=photostorage&uploadfilephoto=true';
    const MODULE_URL = '?module=photostorage';
    const ROUTE_PROXY = 'getimg';
    const EX_NOSCOPE = 'NO_OBJECT_SCOPE_SET';
    const EX_WRONG_EXT = 'WRONG_FILE_EXTENSION';

    /**
     * Initializes photostorage engine for some scope/item id
     * 
     * @param string $scope
     * @param string $itemid
     * 
     * @return void
     */
    public function __construct($scope = '', $itemid = '') {
        $this->loadConfig();
        $this->loadAlter();
        $this->setOptions();
        $this->setScope($scope);
        $this->setItemid($itemid);
        $this->setLogin();
    }

    /**
     * Object scope setter
     * 
     * @param string $scope Object actual scope
     * 
     * @return void
     */
    protected function setScope($scope) {
        $this->scope = mysql_real_escape_string($scope);
    }

    /**
     * Object scope item Id setter
     * 
     * @param string $scope Object actual id in current scope
     * 
     * @return void
     */
    protected function setItemid($itemid) {
        $this->itemId = mysql_real_escape_string($itemid);
    }

    /**
     * Loads system photostorage config into private prop
     * 
     * @return void
     */
    protected function loadConfig() {
        global $ubillingConfig;
        $this->photoCfg = $ubillingConfig->getPhoto();
    }

    /**
     * Sets some current instance specific options.
     * 
     * @return void
     */
    protected function setOptions() {
        if (@$this->altCfg['PHOTOSTORAGE_DIRECTORY']) {
            $this->storagePath = $this->altCfg['PHOTOSTORAGE_DIRECTORY'];
        }

        if (@$this->altCfg['PHOTOSTORAGE_URL_PREFIX']) {
            $this->storageUrlPrefix = $this->altCfg['PHOTOSTORAGE_URL_PREFIX'];
        }

        if (@$this->altCfg['PHOTOSTORAGE_PROXY_MODE']) {
            $this->proxyMode = true;
        }
    }

    /**
     * Loads system alter config into private prop
     * 
     * @return void
     */
    protected function loadAlter() {
        global $ubillingConfig;
        $this->altCfg = $ubillingConfig->getAlter();
    }

    /**
     * Administrator login setter
     * 
     * @return void
     */
    protected function setLogin() {
        $this->myLogin = whoami();
    }

    /**
     * Loads images list from database into private prop
     * 
     * @return void
     */
    protected function loadAllImages() {
        if ((!empty($this->scope)) AND ( !empty($this->itemId))) {
            $query = "SELECT * from `photostorage` ORDER by `id` ASC;";
            $all = simple_queryall($query);
            $this->imagesLoadedFlag = true;
            if (!empty($all)) {
                foreach ($all as $io => $each) {
                    $this->allimages[$each['id']] = $each;

                    if (isset($this->imagesCount[$each['scope']][$each['item']])) {
                        $this->imagesCount[$each['scope']][$each['item']] ++;
                    } else {
                        $this->imagesCount[$each['scope']][$each['item']] = 1;
                    }
                }
            }
        }
    }

    /**
     * Registers uploaded image in database
     * 
     * @param string $filename
     */
    protected function registerImage($filename) {
        if ((!empty($this->scope)) AND ( !empty($this->itemId))) {
            $filename = mysql_real_escape_string($filename);
            $date = curdatetime();
            $query = "INSERT INTO `photostorage` (`id`, `scope`, `item`, `date`, `admin`, `filename`) "
                    . "VALUES (NULL, '" . $this->scope . "', '" . $this->itemId . "', '" . $date . "', '" . $this->myLogin . "', '" . $filename . "'); ";
            nr_query($query);
            log_register('PHOTOSTORAGE CREATE SCOPE `' . $this->scope . '` ITEM [' . $this->itemId . ']');
        }
    }

    /**
     * Deletes uploaded image from database
     * 
     * @param int $imageid
     */
    protected function unregisterImage($imageid) {
        if ((!empty($this->scope)) AND ( !empty($this->itemId))) {
            $imageid = vf($imageid, 3);
            $date = curdatetime();
            $query = "DELETE from `photostorage` WHERE `id`='" . $imageid . "';";
            nr_query($query);
            log_register('PHOTOSTORAGE DELETE SCOPE `' . $this->scope . '` ITEM [' . $this->itemId . ']');
        }
    }

    /**
     * Returns basic image controls
     * 
     * @param int $imageId existing image ID
     * @return string
     */
    protected function imageControls($imageId) {
        $result = wf_tag('br');
        $downloadUrl = self::MODULE_URL . '&scope=' . $this->scope . '&itemid=' . $this->itemId . '&download=' . $imageId;
        $result .= wf_Link($downloadUrl, wf_img('skins/icon_download.png') . ' ' . __('Download'), false, 'ubButton') . ' ';
        if (cfr('PHOTOSTORAGEDELETE')) {
            $deleteUrl = self::MODULE_URL . '&scope=' . $this->scope . '&itemid=' . $this->itemId . '&delete=' . $imageId;
            $result .= wf_AjaxLink($deleteUrl, web_delete_icon() . ' ' . __('Delete'), 'ajRefCont_' . $imageId, false, 'ubButton') . ' ';
        }
        return ($result);
    }

    /**
     * Returns image upload controls
     * 
     * @return string
     */
    public function uploadControlsPanel() {
        $result = '';
        if ((!empty($this->scope)) AND ( !empty($this->itemId))) {
            $result .= wf_Link(self::MODULE_URL . '&scope=' . $this->scope . '&itemid=' . $this->itemId . '&mode=cam', wf_img('skins/photostorage.png') . ' ' . __('Webcamera snapshot'), false, 'ubButton');
            $result .= wf_Link(self::MODULE_URL . '&scope=' . $this->scope . '&itemid=' . $this->itemId . '&mode=avacam', wf_img('skins/photostorage.png') . ' ' . __('Webcamera snapshot') . ' - ' . __('avatar'), false, 'ubButton');
            $result .= wf_Link(self::MODULE_URL . '&scope=' . $this->scope . '&itemid=' . $this->itemId . '&mode=loader', wf_img('skins/photostorage_upload.png') . ' ' . __('Upload file from HDD'), false, 'ubButton');
        }

        return ($result);
    }

    /**
     * Returns custom module backlinks for some scopes
     * 
     * @return string
     */
    protected function backUrlHelper() {
        $result = '';
        if ($this->scope == 'USERPROFILE') {
            $result = web_UserControls($this->itemId);
        }
        if ($this->scope == 'CUSTMAPSITEMS') {
            $result = wf_BackLink('?module=custmaps&edititem=' . $this->itemId);
        }
        if ($this->scope == 'WAREHOUSEITEMTYPE') {
            $result = wf_BackLink('?module=warehouse&itemtypes=true');
        }
        if ($this->scope == 'TASKMAN') {
            $result = wf_BackLink('?module=taskman&edittask=' . $this->itemId);
        }
        if ($this->scope == 'UKVUSERPROFILE') {
            $result = wf_BackLink('?module=ukv&users=true&showuser=' . $this->itemId);
        }
        return ($result);
    }

    /**
     * Returns count of loaded images for some itemid in some scope
     * 
     * @param string $itemId
     * 
     * @return int
     */
    public function getImagesCount($itemId) {
        $result = 0;
        $this->itemId = $itemId;
        if (!$this->imagesLoadedFlag) {
            $this->loadAllImages();
        }

        if (isset($this->imagesCount[$this->scope][$itemId])) {
            $result = $this->imagesCount[$this->scope][$itemId];
        }
        return($result);
    }

    /**
     * Retuns all available scopes and images count in it as scope=>count
     * 
     * @return array
     */
    public function getAvailScopes() {
        $result = array();
        if (!$this->imagesLoadedFlag) {
            $this->loadAllImages();
        }

        if (!empty($this->allimages)) {
            foreach ($this->allimages as $io => $each) {
                if (isset($result[$each['scope']])) {
                    $result[$each['scope']] ++;
                } else {
                    $result[$each['scope']] = 1;
                }
            }
        }
        return($result);
    }

    /**
     * Returns image HTTP accessable URL
     * 
     * @param string $filename
     * 
     * @return string
     */
    protected function getImageUrl($filename) {
        $result = '';
        //Raw HTTP images access
        if (!$this->proxyMode) {
            if (empty($this->storageUrlPrefix)) {
                //seems its local storage
                $result = $this->storagePath . $filename;
            } else {
                //separate images CDN
                $result = $this->storageUrlPrefix . $filename;
            }
        } else {
            //Access to images in storage via proxy-engine
            $result = self::MODULE_URL . '&' . self::ROUTE_PROXY . '=' . $filename;
        }
        return($result);
    }

    /**
     * Returns list of all available images for all scopes
     * 
     * @param int $perPage
     * @param bool $checkRights
     * 
     * @return string
     */
    public function renderScopesGallery($perPage = 12, $checkRights = true) {
        $result = '';
        $paginator = '';
        $scopeImages = array();
        $messages = new UbillingMessageHelper();
        if (!$this->imagesLoadedFlag) {
            $this->loadAllImages();
        }

        if (!empty($this->allimages)) {
            $imgTmp = array_reverse($this->allimages);
            if ($checkRights) {
                $myImages = array();
                if (!cfr('ROOT')) {
                    if (!empty($imgTmp)) {
                        foreach ($imgTmp as $io => $each) {
                            if ($each['admin'] == $this->myLogin) {
                                $myImages[$io] = $each;
                            }
                        }
                        $imgTmp = $myImages;
                    }
                }
            }

            $renderImages = array();
            $totalCount = sizeof($imgTmp);
            $currentPage = (ubRouting::get('page')) ? ubRouting::get('page') : 1;

            //pagination
            if ($totalCount > $perPage) {
                $paginator = wf_pagination($totalCount, $perPage, $currentPage, self::MODULE_URL, 'ubButton', 14);
                $lowLimit = ($perPage * ($currentPage - 1));

                $upperLimit = $lowLimit + $perPage;
                $i = 0;
                foreach ($imgTmp as $io => $each) {
                    if ($i >= $lowLimit AND $i < $upperLimit) {
                        $renderImages[$io] = $each;
                    }
                    $i++;
                }
            } else {
                $renderImages = $imgTmp;
            }

            $galleryRel = 'photostoragegallery';
            $previewStyle = 'style="float:left; margin:1px;"';

            $result .= wf_tag('link', false, '', 'rel="stylesheet" href="modules/jsc/image-gallery-lightjs/src/jquery.light.css"');
            $result .= wf_tag('script', false, '', 'src="modules/jsc/image-gallery-lightjs/src/jquery.light.js"') . wf_tag('script', true);

            if (!empty($renderImages)) {
                foreach ($renderImages as $io => $eachimage) {
                    $imgPreview = wf_img_sized($this->getImageUrl($eachimage['filename']), __('Preview'), $this->photoCfg['IMGLIST_PREV_W'], $this->photoCfg['IMGLIST_PREV_H']);
                    $imgFull = wf_img_sized($this->getImageUrl($eachimage['filename']), '', '100%');
                    $imgCaption = __('Date') . ': ' . $eachimage['date'] . ' ' . __('Admin') . ': ' . $eachimage['admin'];
                    $mngUrl = self::MODULE_URL . '&scope=' . $eachimage['scope'] . '&mode=list&itemid=' . $eachimage['item'];
                    $mngLink = ' ' . wf_Link($mngUrl, __('Show'), false, '', 'target=_blank');
                    $mngLink = str_replace('"', '', $mngLink);
                    $imgCaption .= $mngLink;


                    $galleryOptions = 'data-caption="' . $imgCaption . '" data-gallery="1" rel="' . $galleryRel . '" ' . $previewStyle . '"';
                    $imgGallery = wf_Link($this->getImageUrl($eachimage['filename']), $imgPreview, false, '', $galleryOptions);
                    $result .= $imgGallery;
                }
            } else {
                $result .= $messages->getStyledMessage(__('Nothing to show'), 'info');
            }

            //init gallery
            $jsGallery = wf_tag('script');
            $jsGallery .= " $('a[rel=" . $galleryRel . "]').light({
                            unbind:true,
                            prevText:'" . __('Previous') . "', 
                            nextText:'" . __('Next') . "',
                            loadText:'" . __('Loading') . "...',
                            keyboard:true
                        });
                        ";
            $jsGallery .= wf_tag('script', true);
            $result .= $jsGallery;
            $result .= wf_CleanDiv();
            $result .= $paginator;
        } else {
            $result .= $messages->getStyledMessage(__('Nothing to show'), 'warning');
        }



        return ($result);
    }

    /**
     * Returns current scope/item images list
     * 
     * @return string
     */
    public function renderImagesList() {
        if (!$this->imagesLoadedFlag) {
            $this->loadAllImages();
        }

        $result = wf_AjaxLoader();

        if (!empty($this->allimages)) {
            foreach ($this->allimages as $io => $eachimage) {
                if (($eachimage['scope'] == $this->scope) AND ( $eachimage['item'] == $this->itemId)) {
                    $imgPreview = wf_img_sized($this->getImageUrl($eachimage['filename']), __('Show'), $this->photoCfg['IMGLIST_PREV_W'], $this->photoCfg['IMGLIST_PREV_H']);
                    $imgFull = wf_img_sized($this->getImageUrl($eachimage['filename']), '', '100%');
                    $imgFull .= wf_tag('br');
                    $imgFull .= __('Date') . ': ' . $eachimage['date'] . ' / ';
                    $imgFull .= __('Admin') . ': ' . $eachimage['admin'];

                    $dimensions = 'width:' . ($this->photoCfg['IMGLIST_PREV_W'] + 10) . 'px;';
                    $dimensions .= 'height:' . ($this->photoCfg['IMGLIST_PREV_H'] + 10) . 'px;';
                    $result .= wf_tag('div', false, '', 'style="float:left;  ' . $dimensions . ' padding:15px;" id="ajRefCont_' . $eachimage['id'] . '"');
                    $result .= wf_modalAuto($imgPreview, __('Image') . ' ' . $eachimage['id'], $imgFull, '');
                    $result .= $this->imageControls($eachimage['id']);
                    $result .= wf_tag('div', true);
                }
            }
        }


        $result .= wf_tag('div', false, '', 'style="clear:both;"') . wf_tag('div', true);
        $result .= wf_delimiter();
        $result .= $this->backUrlHelper();
        return ($result);
    }

    /**
     * Returns list of available images for current scope/item
     * 
     * @return string
     */
    public function renderImagesRaw() {
        $result = '';
        $galleryFlag = ($this->altCfg['PHOTOSTORAGE_GALLERY']) ? true : false;

        if (!$this->imagesLoadedFlag) {
            $this->loadAllImages();
        }

        if (!empty($this->allimages)) {
            $galleryRel = 'photostoragegallery';
            $previewStyle = 'style="float:left; margin:1px;"';

            $result .= wf_tag('link', false, '', 'rel="stylesheet" href="modules/jsc/image-gallery-lightjs/src/jquery.light.css"');
            $result .= wf_tag('script', false, '', 'src="modules/jsc/image-gallery-lightjs/src/jquery.light.js"') . wf_tag('script', true);

            foreach ($this->allimages as $io => $eachimage) {
                if (($eachimage['scope'] == $this->scope) AND ( $eachimage['item'] == $this->itemId)) {
                    $imgPreview = wf_img_sized($this->getImageUrl($eachimage['filename']), __('Show'), $this->photoCfg['IMGLIST_PREV_W'], $this->photoCfg['IMGLIST_PREV_H']);
                    $imgFull = wf_img_sized($this->getImageUrl($eachimage['filename']), '', '100%');
                    $imgCaption = __('Date') . ': ' . $eachimage['date'] . ' ' . __('Admin') . ': ' . $eachimage['admin'];

                    if ($galleryFlag) {
                        $galleryOptions = 'data-caption="' . $imgCaption . '" data-gallery="1" rel="' . $galleryRel . '" ' . $previewStyle . '"';
                        $imgGallery = wf_Link($this->getImageUrl($eachimage['filename']), $imgPreview, false, '', $galleryOptions);
                        $result .= $imgGallery;
                    } else {
                        $result .= wf_modalAuto($imgPreview, __('Image') . ' ' . $eachimage['id'], $imgFull . $imgCaption, '');
                    }
                }
            }

            //init gallery
            $jsGallery = wf_tag('script');
            $jsGallery .= " $('a[rel=" . $galleryRel . "]').light({
                            unbind:true,
                            prevText:'" . __('Previous') . "', 
                            nextText:'" . __('Next') . "',
                            loadText:'" . __('Loading') . "...',
                            keyboard:true
                        });
                        ";
            $jsGallery .= wf_tag('script', true);
            $result .= $jsGallery;
        }


        $result .= wf_CleanDiv();
        return ($result);
    }

    /**
     * Downloads image file by its id
     * 
     * @param int $id database image ID
     */
    public function catchDownloadImage($id) {
        $id = vf($id, 3);
        if (!$this->imagesLoadedFlag) {
            $this->loadAllImages();
        }
        if (!empty($id)) {
            @$filename = $this->allimages[$id]['filename'];
            if (file_exists($this->storagePath . $filename)) {
                zb_DownloadFile($this->storagePath . $filename, 'jpg');
            } else {
                show_error(__('File not exist'));
            }
        } else {
            show_error(__('Image not exists'));
        }
    }

    /**
     * Returns some image content as is if proxy mode enabled \
     * and image file exists in storage path
     * 
     * @param string $filename
     */
    public function proxyImage($filename) {
        if ($this->proxyMode) {
            if (file_exists($this->storagePath . $filename)) {
                $imageContent = file_get_contents($this->storagePath . $filename);
                die($imageContent);
            } else {
                $noImage = file_get_contents('skins/noimage.jpg');
                die($noImage);
            }
        } else {
            $noImage = file_get_contents('skins/noimage.jpg');
            die($noImage);
        }
    }

    /**
     * deletes image from database and FS by its ID
     * 
     * @param int $id database image ID
     */
    public function catchDeleteImage($id) {
        $id = vf($id, 3);
        if (!$this->imagesLoadedFlag) {
            $this->loadAllImages();
        }
        if (!empty($id)) {
            @$filename = $this->allimages[$id]['filename'];
            if (file_exists($this->storagePath . $filename)) {
                if (cfr('PHOTOSTORAGEDELETE')) {
                    unlink($this->storagePath . $filename);
                    $this->unregisterImage($id);
                    $deleteResult = wf_tag('span', false, 'alert_warning') . __('Deleted') . wf_tag('span', true);
                } else {
                    $deleteResult = wf_tag('span', false, 'alert_error') . __('Access denied') . wf_tag('span', true);
                }
            } else {
                $deleteResult = wf_tag('span', false, 'alert_error') . __('File not exist') . wf_tag('span', true);
            }
        } else {
            $deleteResult = wf_tag('span', false, 'alert_error') . __('Image not exists') . wf_tag('span', true);
        }
        die($deleteResult);
    }

    /**
     * Catches webcam snapshot upload in background
     * 
     * @return void
     */
    public function catchWebcamUpload() {
        if (wf_CheckGet(array('uploadcamphoto'))) {
            if (!empty($this->scope)) {
                $newWebcamFilename = date("Y_m_d_His") . '_' . zb_rand_string(8) . '_webcam.jpg';
                $newWebcamSavePath = $this->storagePath . $newWebcamFilename;
                move_uploaded_file($_FILES['webcam']['tmp_name'], $newWebcamSavePath);
                if (file_exists($newWebcamSavePath)) {
                    $uploadResult = wf_tag('span', false, 'alert_success') . __('Photo upload complete') . wf_tag('span', true);
                    $this->registerImage($newWebcamFilename);
                } else {
                    $uploadResult = wf_tag('span', false, 'alert_error') . __('Photo upload failed') . wf_tag('span', true);
                }
            } else {
                $uploadResult = wf_tag('span', false, 'alert_error') . __('Strange exeption') . ': ' . self::EX_NOSCOPE . wf_tag('span', true);
            }
            die($uploadResult);
        }
    }

    /**
     * Catches file upload in background
     *
     * @param string $customBackLink
     * 
     * @return void
     */
    public function catchFileUpload($customBackLink = '') {
        if (wf_CheckGet(array('uploadfilephoto'))) {
            if (!empty($this->scope)) {
                $allowedExtensions = array("jpg", "gif", "png", "jpeg");
                $fileAccepted = true;
                foreach ($_FILES as $file) {
                    if ($file['tmp_name'] > '') {
                        //TODO: in PHP 7.1 following string generates notice
                        if (@!in_array(end(explode(".", strtolower($file['name']))), $allowedExtensions)) {
                            $fileAccepted = false;
                        }
                    }
                }

                if ($fileAccepted) {
                    $newFilename = date("Y_m_d_His") . '_' . zb_rand_string(8) . '_upload.jpg';
                    $newSavePath = $this->storagePath . $newFilename;
                    @move_uploaded_file($_FILES['photostorageFileUpload']['tmp_name'], $newSavePath);
                    if (file_exists($newSavePath)) {
                        $uploadResult = wf_tag('span', false, 'alert_success') . __('Photo upload complete') . wf_tag('span', true);
                        $this->registerImage($newFilename);

                        // forwarding $customBackLink back to renderUploadForm() routine
                        if (empty($customBackLink)) {
                            $customBackLink = '';
                        } else {
                            $customBackLink = '&custombacklink=' . $customBackLink;
                        }

                        rcms_redirect(self::MODULE_URL . '&scope=' . $this->scope . '&itemid=' . $this->itemId . '&mode=loader&preview=' . $newFilename . $customBackLink);
                    } else {
                        $uploadResult = wf_tag('span', false, 'alert_error') . __('Photo upload failed') . wf_tag('span', true);
                    }
                } else {
                    $uploadResult = wf_tag('span', false, 'alert_error') . __('Photo upload failed') . ': ' . self::EX_WRONG_EXT . wf_tag('span', true);
                }
            } else {
                $uploadResult = wf_tag('span', false, 'alert_error') . __('Strange exeption') . ': ' . self::EX_NOSCOPE . wf_tag('span', true);
            }

            show_window('', $uploadResult);
            show_window('', wf_BackLink(self::MODULE_URL . '&scope=' . $this->scope . '&itemid=' . $this->itemId . '&mode=loader'));
        }
    }

    /**
     * Returns file upload form
     *
     * @param bool $embedded
     * @param string $customBackLink
     *
     * @return string
     */
    public function renderUploadForm($embedded = false, $customBackLink = '') {
        // forwarding $customBackLink to catchFileUpload() routine
        if (!empty($customBackLink)) {
            $customBackLink = '&custombacklink=' . $customBackLink;
        }

        $postUrl = self::UPLOAD_URL_FILE . '&scope=' . $this->scope . '&itemid=' . $this->itemId . $customBackLink;
        $inputs = wf_tag('form', false, 'glamour', 'action="' . $postUrl . '" enctype="multipart/form-data" method="POST"');
        $inputs .= wf_tag('input', false, '', 'type="file" name="photostorageFileUpload"');
        $inputs .= wf_Submit(__('Upload'));
        $inputs .= wf_tag('form', true);

        $result = $inputs;
        $result .= wf_delimiter(2);
        if (wf_CheckGet(array('preview'))) {
            $result .= wf_img_sized($this->getImageUrl(ubRouting::get('preview')), __('Preview'), $this->photoCfg['IMGLIST_PREV_W'], $this->photoCfg['IMGLIST_PREV_H']);
            $result .= wf_delimiter();
            $result .= wf_tag('span', false, 'alert_success') . __('Photo upload complete') . wf_tag('span', true);
            $result .= wf_delimiter();
        }

        if (!$embedded) {
            // checking for forwarded $customBackLink from catchFileUpload() routine
            if (ubRouting::checkGet('custombacklink')) {
                $result .= wf_BackLink(base64_decode(ubRouting::get('custombacklink')));
            } else {
                $result .= wf_BackLink(self::MODULE_URL . '&scope=' . $this->scope . '&itemid=' . $this->itemId . '&mode=list');
            }
        }

        return ($result);
    }

    /**
     * Returns webcamera snapshot form
     * 
     * @param bool $avatarMode use crop by WEBCAM_AVA_CROP property
     * 
     * @return string
     */
    public function renderWebcamForm($avatarMode = false) {
        $container = wf_tag('div', false, '', 'id="cameraContainer"');
        $container .= wf_tag('div', true);
        $container .= wf_tag('script', false, '', 'type="text/javascript" src="modules/jsc/webcamjs/webcam.min.js"');
        $container .= wf_tag('script', true);

        if ($avatarMode) {
            $cropControls = 'crop_width: ' . $this->photoCfg['WEBCAM_AVA_CROP'] . ',
                           crop_height: ' . $this->photoCfg['WEBCAM_AVA_CROP'] . ',';
            $prev_w = $this->photoCfg['WEBCAM_PREV_W'];
            $prev_h = $this->photoCfg['WEBCAM_PREV_H'];
            $dest_w = $this->photoCfg['WEBCAM_RESULT_W'];
            $dest_h = $this->photoCfg['WEBCAM_RESULT_H'];
        } else {
            $cropControls = '';
            $prev_w = $this->photoCfg['WEBCAM_PREV_W'];
            $prev_h = $this->photoCfg['WEBCAM_PREV_H'];
            $dest_w = $this->photoCfg['WEBCAM_RESULT_W'];
            $dest_h = $this->photoCfg['WEBCAM_RESULT_H'];
        }

        $init = wf_tag('script', false, '', 'language="JavaScript"');
        $init .= '	Webcam.set({
                        ' . $cropControls . '
			width: ' . $prev_w . ',
			height: ' . $prev_h . ',
			dest_width: ' . $dest_w . ',
			dest_height: ' . $dest_h . ',
			image_format: \'' . $this->photoCfg['WEBCAM_FORMAT'] . '\',
			jpeg_quality: ' . $this->photoCfg['WEBCAM_JPEG_QUALITY'] . ',
                        force_flash: ' . $this->photoCfg['WEBCAM_FORCE_FLASH'] . '
		});
		Webcam.attach( \'#cameraContainer\' );';
        $init .= wf_tag('script', true);

        $uploadJs = wf_tag('script', false, '', 'language="JavaScript"');
        $uploadJs .= '	var shutter = new Audio();
                        shutter.autoplay = false;
                        shutter.src = navigator.userAgent.match(/Firefox/) ? \'modules/jsc/webcamjs/shutter.ogg\' : \'modules/jsc/webcamjs/shutter.mp3\';
                        function take_snapshot() {
                                shutter.play();
                                Webcam.snap( function(data_uri) {
                                        document.getElementById(\'webcamResults\').innerHTML = 
                                                \'<img src="\'+data_uri+\'" width=' . $prev_w . ' height=' . $prev_h . ' />\';

                                                var url = \'' . self::UPLOAD_URL_WEBC . '&scope=' . $this->scope . '&itemid=' . $this->itemId . '\';
                                                Webcam.upload( data_uri, url, function(code, text) {

                                            } );
                                        } );
                               Webcam.on( \'uploadProgress\', function(progress) {
                               document.getElementById(\'uploadProgress\').innerHTML=\'<img src="skins/ajaxloader.gif">\';
                               } );
                               Webcam.on( \'uploadComplete\', function(code, text) {
                                document.getElementById(\'uploadProgress\').innerHTML=text;
                               } );
                            }';
        $uploadJs .= wf_tag('script', true);

        $form = wf_tag('br');
        $form .= wf_tag('form', false);
        $form .= wf_tag('input', false, '', 'type=button value="' . __('Take snapshot') . '" onClick="take_snapshot()"');
        $form .= wf_tag('form', true);

        $preview = wf_tag('div', false, '', 'id="webcamResults"');
        $preview .= wf_tag('div', true);

        $uploadProgress = wf_tag('div', false, '', 'id="uploadProgress"');
        $uploadProgress .= wf_tag('div', true);

        $cells = wf_TableCell($container . $init . $form . $uploadJs, '50%', '', 'valign="top"');
        $cells .= wf_TableCell($preview, '', '', 'valign="top"');
        $rows = wf_TableRow($cells);

        $result = wf_TableBody($rows, '100%', 0);
        $result .= $uploadProgress;
        $result .= wf_delimiter();
        $result .= wf_BackLink(self::MODULE_URL . '&scope=' . $this->scope . '&itemid=' . $this->itemId . '&mode=list');


        return ($result);
    }

}

?>