<?php
/**
* Manage and give access to lizmap configuration.
* @package   lizmap
* @subpackage lizmap
* @author    3liz
* @copyright 2012 3liz
* @link      http://3liz.com
* @license Mozilla Public License : http://www.mozilla.org/MPL/
*/


class lizmapProject{

    // lizmapRepository
    protected $repository = null;
    // QGIS project XML
    protected $xml = null;
    // CFG project JSON
    protected $cfg = null;

    // services properties
    protected $properties = array(
        'repository',
        'id',
        'title',
        'abstract',
        'proj',
        'bbox'
    );
    // Lizmap repository key
    protected $key = '';
    // Lizmap repository configuration data
    protected $data = array();
    // Version of QGIS which wrote the project
    protected $qgisProjectVersion = null;

    /**
     * @var array contains WMS info
     */
    protected $WMSInformation = null;

    /**
     * @var string
     */
    protected $canvasColor = '';

    /**
     * @var array  authid => proj4
     */
    protected $allProj4 = array();

    /**
     * @var array  for each referenced layer, there is an item
     *            with referencingLayer, referencedField, referencingField keys.
     *            There is also a 'pivot' key
     */
    protected $relations = array();

    /**
     * @var array list of layer orders: layer name => order
     */
    protected $layersOrder = array();

    /**
     * @var array
     */
    protected $printCapabilities = array();

    /**
     * constructor
     * key : the project name
     * rep : the repository has a lizmapRepository class
     */
    public function __construct ( $key, $rep ) {
        $this->readXml($key, $rep);
    }

    protected function readXml($key, $rep) {
        if (!file_exists($rep->getPath().$key.'.qgs') ||
            !file_exists($rep->getPath().$key.'.qgs.cfg') ) {
            return;
        }
        $this->key = $key;
        $this->repository = $rep;

        $key_session = $rep->getKey().'~'.$key;
        $qgs_path = $rep->getPath().$key.'.qgs';
        $config = null;
        $qgs_xml = null;

        $config = jFile::read($qgs_path.'.cfg');
        $this->cfg = json_decode($config);

        $configOptions = $this->cfg->options;

        $qgs_xml = simplexml_load_file($qgs_path);
        $this->xml = $qgs_xml;

        $this->data = array(
            'repository'=>$rep->getKey(),
            'id'=>$key,
            'title'=>ucfirst($key),
            'abstract'=>'',
            'proj'=> $configOptions->projection->ref,
            'bbox'=> join($configOptions->bbox,', ')
        );
        # get title from WMS properties
        if (property_exists($qgs_xml->properties, 'WMSServiceTitle'))
            if (!empty($qgs_xml->properties->WMSServiceTitle))
                $this->data['title'] = $qgs_xml->properties->WMSServiceTitle;

        # get abstract from WMS properties
        if (property_exists($qgs_xml->properties, 'WMSServiceAbstract'))
            $this->data['abstract'] = $qgs_xml->properties->WMSServiceAbstract;

        # get WMS getCapabilities full URL
        $this->data['wmsGetCapabilitiesUrl'] = jUrl::getFull(
            'lizmap~service:index',
            array(
                'repository' => $rep->getKey(),
                'project' => $key,
                'SERVICE' => 'WMS',
                'VERSION' => '1.3.0',
                'REQUEST' => 'GetCapabilities'
            )
        );

        # get WMTS getCapabilities full URL
        $this->data['wmtsGetCapabilitiesUrl'] = jUrl::getFull(
            'lizmap~service:index',
            array(
                'repository' => $rep->getKey(),
                'project' => $key,
                'SERVICE' => 'WMTS',
                'VERSION' => '1.0.0',
                'REQUEST' => 'GetCapabilities'
            )
        );

        // get QGIS project version
        $qgisRoot = $this->xml->xpath('//qgis');
        $qgisRootZero = $qgisRoot[0];
        $qgisProjectVersion = (string)$qgisRootZero->attributes()->version;
        $qgisProjectVersion = explode('-', $qgisProjectVersion);
        $qgisProjectVersion = $qgisProjectVersion[0];
        $qgisProjectVersion = explode('.', $qgisProjectVersion);
        $a = '';
        foreach( $qgisProjectVersion as $k ){
            if( strlen($k) == 1 ){
                $a.= $k . '0';
            }
            else {
                $a.= $k;
            }
        }
        $qgisProjectVersion = (integer)$a;
        $this->qgisProjectVersion = $qgisProjectVersion;

        $shortNames = $this->xml->xpath('//maplayer/shortname');
        if ( count( $shortNames ) > 0 ) {
            foreach( $shortNames as $sname ) {
                $sname = (string) $sname;
                $xmlLayer = $qgs_xml->xpath( "//maplayer[shortname='$sname']" );
                $xmlLayer = $xmlLayer[0];
                $name = (string)$xmlLayer->layername;
                if ( property_exists($this->cfg->layers, $name ) )
                    $this->cfg->layers->$name->shortname = $sname;
            }
        }

        $groupsWithShortName = $this->xml->xpath("//layer-tree-group/customproperties/property[@key='wmsShortName']/parent::*/parent::*");
        if ( count( $groupsWithShortName ) > 0 ) {
            foreach( $groupsWithShortName as $group ) {
                $name = (string)$group['name'];
                $shortNameProperty = $group->xpath("customproperties/property[@key='wmsShortName']");
                if ( count( $shortNameProperty ) > 0 ) {
                    $shortNameProperty = $shortNameProperty[0];
                    $sname = (string) $shortNameProperty['value'];
                    if ( property_exists($this->cfg->layers, $name ) )
                        $this->cfg->layers->$name->shortname = $sname;
                }
            }
        }

        $layersWithShowFeatureCount = $this->xml->xpath("//layer-tree-layer/customproperties/property[@key='showFeatureCount']/parent::*/parent::*");
        if ( count( $layersWithShowFeatureCount ) > 0 ) {
            foreach( $layersWithShowFeatureCount as $layer ) {
                $name = (string)$layer['name'];
                if ( property_exists($this->cfg->layers, $name ) )
                    $this->cfg->layers->$name->showFeatureCount = 'True';
            }
        }
        //unset cache for editionLayers
        if (property_exists($this->cfg, 'editionLayers') ){
            foreach( $this->cfg->editionLayers as $key=>$obj ){
                if (property_exists($this->cfg->layers, $key) ){
                    $this->cfg->layers->$key->cached = 'False';
                    $this->cfg->layers->$key->clientCacheExpiration = 0;
                    if ( property_exists($this->cfg->layers->$key, 'cacheExpiration') )
                        unset($this->cfg->layers->$key->cacheExpiration);
                }
            }
        }
        //unset cache for loginFilteredLayers
        if ( property_exists($this->cfg,'loginFilteredLayers') ){
            foreach( $this->cfg->loginFilteredLayers as $key=>$obj ){
                if (property_exists($this->cfg->layers, $key) ){
                    $this->cfg->layers->$key->cached = 'False';
                    $this->cfg->layers->$key->clientCacheExpiration = 0;
                    if ( property_exists($this->cfg->layers->$key, 'cacheExpiration') )
                        unset($this->cfg->layers->$key->cacheExpiration);
                }
            }
        }
        //unset displayInLegend for geometryType none or unknown
        foreach( $this->cfg->layers as $key=>$obj ){
            if ( property_exists($this->cfg->layers->$key, 'geometryType') &&
                 ($this->cfg->layers->$key->geometryType == 'none' || $this->cfg->layers->$key->geometryType == 'unknown') )
                $this->cfg->layers->$key->displayInLegend = 'False';
        }

        $this->WMSInformation = $this->readWMSInformation($this->xml);
        $this->canvasColor = $this->readCanvasColor($this->xml);
        $this->allProj4 = $this->readAllProj4($this->xml);
        $this->relations = $this->readRelations($this->xml);
        $this->layersOrder = $this->readLayersOrder($this->xml);
        $this->printCapabilities = $this->readPrintCapabilities($this->xml, $this->cfg);
    }

    public function getQgisProjectVersion(){
        return $this->qgisProjectVersion;
    }

    public function getQgisPath(){
        return realpath($this->repository->getPath()).'/'.$this->key.'.qgs';
    }

    public function getKey(){
        return $this->key;
    }

    public function getRepository(){
        return $this->repository;
    }

    public function getProperties(){
        return $this->properties;
    }

    public function getData( $key ) {
        if ( !array_key_exists($key, $this->data) )
            return null;
        return $this->data[$key];
    }

    public function getOptions(){
        return $this->cfg->options;
    }

    public function getLayers(){
        return $this->cfg->layers;
    }


    public function findLayerByAnyName( $name ){
        // Get by name ie as written in QGIS Desktop legend
        $layer = $this->findLayerByName( $name );
        if ( $layer  ) return $layer;

        // since 2.14 layer's name can be layer's shortName
        $layer = $this->findLayerByShortName( $name );
        if ( $layer  ) return $layer;

        // Get layer by typename : qgis server replaces ' ' by '_' for layer names
        $layer = $this->findLayerByTypeName( $name );
        if ( $layer  ) return $layer;

        // Get by id
        $layer = $this->findLayerByLayerId( $name );
        if ( $layer  ) return $layer;

        // since 2.6 layer's name can be layer's title
        $layer = $this->findLayerByTitle( $name );
        return $layer;
    }

    public function findLayerByName( $name ){
        if ( property_exists($this->cfg->layers, $name ) )
            return $this->cfg->layers->$name;
        return null;
    }

    public function findLayerByShortName( $shortName ){
        foreach ( $this->cfg->layers as $layer ) {
            if ( !property_exists( $layer, 'shortname' ) )
                continue;
            if ( $layer->shortname == $shortName )
                return $layer;
        }
        return null;
    }

    public function findLayerByTitle( $title ){
        foreach ( $this->cfg->layers as $layer ) {
            if ( !property_exists( $layer, 'title' ) )
                continue;
            if ( $layer->title == $title )
                return $layer;
        }
        return null;
    }

    public function findLayerByLayerId( $layerId ){
        foreach ( $this->cfg->layers as $layer ) {
            if ( !property_exists( $layer, 'id' ) )
                continue;
            if ( $layer->id == $layerId )
                return $layer;
        }
        return null;
    }

    public function findLayerByTypeName( $typeName ){
        if ( property_exists($this->cfg->layers, $typeName ) )
            return $this->cfg->layers->$typeName;
        $layerName = str_replace('_', ' ', $typeName );
        if ( property_exists($this->cfg->layers, $layerName ) )
            return $this->cfg->layers->$layerName;
        return null;
    }

    public function hasLocateByLayer(){
        if ( property_exists($this->cfg,'locateByLayer') ){
            $count = 0;
            foreach( $this->cfg->locateByLayer as $key=>$obj ){
                $count += 1;
            }
            if ( $count != 0 )
                return true;
            return false;
        }
        return false;
    }

    public function hasTimemanagerLayers(){
        if ( property_exists($this->cfg,'timemanagerLayers') ){
            $count = 0;
            foreach( $this->cfg->timemanagerLayers as $key=>$obj ){
                $count += 1;
            }
            if ( $count != 0 )
                return true;
            return false;
        }
        return false;
    }

    public function hasTooltipLayers(){
        if ( property_exists($this->cfg,'tooltipLayers') ){
            $count = 0;
            foreach( $this->cfg->tooltipLayers as $key=>$obj ){
                $count += 1;
            }
            if ( $count != 0 )
                return true;
            return false;
        }
        return false;
    }

    public function hasAttributeLayers(){
        if ( property_exists($this->cfg,'attributeLayers') ){
            $count = 0;
            foreach( $this->cfg->attributeLayers as $key=>$obj ){
                $count += 1;
            }
            if ( $count != 0 )
                return true;
            return false;
        }
        return false;
    }

    public function hasEditionLayers(){
        if ( property_exists($this->cfg,'editionLayers') ){
            if(!jAcl2::check('lizmap.tools.edition.use', $this->repository->getKey()))
                return false;

            $count = 0;
            foreach( $this->cfg->editionLayers as $key=>$obj ){
                $count += 1;
            }
            if ( $count != 0 )
                return true;
            return false;
        }
        return false;
    }

    public function getEditionLayers(){
        return $this->cfg->editionLayers;
    }

    public function findEditionLayerByName( $name ){
        if ( !$this->hasEditionLayers() )
            return null;

        if ( property_exists($this->cfg->editionLayers, $name ) )
            return $this->cfg->editionLayers->$name;
        return null;
    }

    public function findEditionLayerByLayerId( $layerId ){
        if ( !$this->hasEditionLayers() )
            return null;

        foreach ( $this->cfg->editionLayers as $layer ) {
            if ( !property_exists( $layer, 'layerId' ) )
                continue;
            if ( $layer->layerId == $layerId )
                return $layer;
        }
        return null;
    }

    public function hasLoginFilteredLayers(){
        if ( property_exists($this->cfg,'loginFilteredLayers') ){
            $count = 0;
            foreach( $this->cfg->loginFilteredLayers as $key=>$obj ){
                $count += 1;
            }
            if ( $count != 0 )
                return true;
            return false;
        }
        return false;
    }

    public function needsGoogle(){
        $configOptions = $this->cfg->options;
        return (
            (
                property_exists($configOptions,'googleStreets')
                && $configOptions->googleStreets == 'True'
            ) ||
            (
                property_exists($configOptions,'googleSatellite')
                && $configOptions->googleSatellite == 'True'
            ) ||
            (
                property_exists($configOptions,'googleHybrid')
                && $configOptions->googleHybrid == 'True'
            ) ||
            (
                property_exists($configOptions,'googleTerrain')
                && $configOptions->googleTerrain == 'True'
            ) ||
            (
                property_exists($configOptions,'externalSearch')
                && $configOptions->externalSearch == 'google'
            )
        );
    }

    public function getGoogleKey(){
        $configOptions = $this->cfg->options;
        $gkey = '';
        if (property_exists($configOptions,'googleKey')
            && $configOptions->googleKey != '')
            $gkey = $configOptions->googleKey;
        return $gkey;
    }

    public function getLayerNameByIdFromConfig( $layerId ){
        $layers = $this->getLayers();
        $name = null;
        foreach ($layers as $name=>$props){
            if( $props->id == $layerId)
                return $name;
        }
        return $name;
    }

    public function getWMSInformation(){
        return $this->WMSInformation;
    }

    protected function readWMSInformation($qgsLoad) {

        // Default metadata
        $WMSServiceTitle = '';
        $WMSServiceAbstract = '';
        $WMSExtent = '';
        $ProjectCrs = '';
        $WMSOnlineResource = '';
        $WMSContactMail = '';
        $WMSContactOrganization = '';
        $WMSContactPerson = '';
        $WMSContactPhone = '';
        if($qgsLoad){
            $WMSServiceTitle = (string)$qgsLoad->properties->WMSServiceTitle;
            $WMSServiceAbstract = (string)$qgsLoad->properties->WMSServiceAbstract;
            $WMSExtent = $qgsLoad->properties->WMSExtent->value[0];
            $WMSExtent.= ", ".$qgsLoad->properties->WMSExtent->value[1];
            $WMSExtent.= ", ".$qgsLoad->properties->WMSExtent->value[2];
            $WMSExtent.= ", ".$qgsLoad->properties->WMSExtent->value[3];
            $ProjectCrs = (string)$this->data['proj'];
            $WMSOnlineResource = (string)$qgsLoad->properties->WMSOnlineResource;
            $WMSContactMail = (string)$qgsLoad->properties->WMSContactMail;
            $WMSContactOrganization = (string)$qgsLoad->properties->WMSContactOrganization;
            $WMSContactPerson= (string)$qgsLoad->properties->WMSContactPerson;
            $WMSContactPhone = (string)$qgsLoad->properties->WMSContactPhone;
        }

        return array(
            'WMSServiceTitle'=>$WMSServiceTitle,
            'WMSServiceAbstract'=>$WMSServiceAbstract,
            'WMSExtent'=>$WMSExtent,
            'ProjectCrs'=>$ProjectCrs,
            'WMSOnlineResource'=>$WMSOnlineResource,
            'WMSContactMail'=>$WMSContactMail,
            'WMSContactOrganization'=>$WMSContactOrganization,
            'WMSContactPerson'=>$WMSContactPerson,
            'WMSContactPhone'=>$WMSContactPhone
        );
    }

    protected function readLayersOrder($qgsLoad) {
        $legend = $qgsLoad->xpath('//legend');
        $legendZero = $legend[0];
        $updateDrawingOrder = (string)$legendZero->attributes()->updateDrawingOrder;
        $layersOrder = array();

        if( $updateDrawingOrder == 'false' ){
            // For QGIS >=2.4, new item layer-tree-canvas
            if( $this->qgisProjectVersion >= 204000){
                $customeOrder = $qgsLoad->xpath('//layer-tree-canvas/custom-order');
                $customeOrderZero = $customeOrder[0];
                if( $customeOrderZero->attributes()->enabled == 1 ){
                    $items = $customeOrderZero->xpath('//item');
                    $lo = 0;
                    foreach( $items as $layerI ) {
                        # Get layer name from config instead of XML for possible embedded layers
                        $name = $this->getLayerNameByIdFromConfig($layerI);
                        if( $name ){
                            $layersOrder[$name] = $lo;
                        }
                        $lo+=1;
                    }
                }
            } else {
                $layers =  $qgsLoad->xpath('//legendlayer');
                foreach( $layers as $layer ){
                    if( $layer->attributes()->drawingOrder and $layer->attributes()->drawingOrder >= 0 ){
                        $layersOrder[(string)$layer->attributes()->name] = (integer)$layer->attributes()->drawingOrder;
                    }
                }
            }
        }
        return $layersOrder;
    }

    protected function readPrintCapabilities($qgsLoad, $cfg) {
        $printTemplates = array();

        if( property_exists($cfg->options, 'print')
            && $cfg->options->print == 'True' ) {
            // get restricted composers
            $rComposers = array();
            $restrictedComposers = $qgsLoad->xpath( "//properties/WMSRestrictedComposers/value" );
            foreach($restrictedComposers as $restrictedComposer){
                $rComposers[] = (string)$restrictedComposer;
            }
            // get composer
            $composers =  $qgsLoad->xpath('//Composer');
            foreach($composers as $composer){
                // test restriction
                if( in_array((string)$composer['title'], $rComposers) )
                    continue;
                // get composition element
                $composition = $composer->xpath('Composition');
                if( count($composition) == 0 )
                    continue;
                $composition = $composition[0];

                // init print template element
                $printTemplate = array(
                    'title'=>(string)$composer['title'],
                    'width'=>(int)$composition['paperWidth'],
                    'height'=>(int)$composition['paperHeight'],
                    'maps'=>array(),
                    'labels'=>array()
                );

                // get composer maps
                $cMaps = $composer->xpath('.//ComposerMap');
                foreach( $cMaps as $cMap ) {
                    $cMapItem = $cMap->xpath('ComposerItem');
                    if( count($cMapItem) == 0 )
                        continue;
                    $cMapItem = $cMapItem[0];
                    $ptMap = array(
                        'id'=>'map'.(string)$cMap['id'],
                        'width'=>(int)$cMapItem['width'],
                        'height'=>(int)$cMapItem['height'],
                    );

                    // Before 2.6
                    if ( property_exists( $cMap->attributes(), 'overviewFrameMap' ) and (string)$cMap['overviewFrameMap'] != '-1' ){
                        $ptMap['overviewMap'] = 'map'.(string)$cMap['overviewFrameMap'];
                    }
                    // >= 2.6
                    $cMapOverviews = $cMap->xpath('ComposerMapOverview');
                    foreach($cMapOverviews as $cMapOverview){
                        if ( $cMapOverview and (string)$cMapOverview->attributes()->frameMap != '-1' ){
                            $ptMap['overviewMap'] = 'map' . (string)$cMapOverview->attributes()->frameMap;
                        }
                    }

                    $printTemplate['maps'][] = $ptMap;
                }

                // get composer labels
                $cLabels = $composer->xpath('.//ComposerLabel');
                foreach( $cLabels as $cLabel ) {
                    $cLabelItem = $cLabel->xpath('ComposerItem');
                    if( count($cLabelItem) == 0 )
                        continue;
                    $cLabelItem = $cLabelItem[0];
                    if( (string)$cLabelItem['id'] == '' )
                        continue;
                    $printTemplate['labels'][] = array(
                        'id'=>(string)$cLabelItem['id'],
                        'htmlState'=>(int)$cLabel['htmlState'],
                        'text'=>(string)$cLabel['labelText']
                    );
                }
                $printTemplates[] = $printTemplate;
            }
        }
        return $printTemplates;
    }

    public function getUpdatedConfig(){
        $qgsLoad = $this->xml;

        //FIXME: it's better to use clone keyword
        $configRead = json_encode($this->cfg);
        $configJson = json_decode($configRead);

        // Add an option to display buttons to remove the cache for cached layer
        // Only if appropriate right is found
        if( jAcl2::check('lizmap.admin.repositories.delete') ){
            $configJson->options->removeCache = 'True';
        }

        // Remove layerOrder option from config if not required
        if(!empty($this->layersOrder)){
            $configJson->layersOrder = $this->layersOrder;
        }

        // set printTemplates in config
        $configJson->printTemplates = $this->printCapabilities;

        // Update locate by layer with vecctorjoins
        if(property_exists($configJson, 'locateByLayer')) {
            // collect layerIds
            $locateLayerIds = array();
            foreach( $configJson->locateByLayer as $k=>$v) {
                    $locateLayerIds[] = $v->layerId;
            }
            // update locateByLayer with alias and filter information
            foreach( $configJson->locateByLayer as $k=>$v) {
                $xmlLayer = $this->getXmlLayer( $v->layerId );
                $xmlLayerZero = $xmlLayer[0];
                // aliases
                $alias = $xmlLayerZero->xpath("aliases/alias[@field='".$v->fieldName."']");
                if( count($alias) != 0 ) {
                    $alias = $alias[0];
                    $v->fieldAlias = (string)$alias['name'];
                    $configJson->locateByLayer->$k = $v;
                }
                if ( property_exists( $v, 'filterFieldName') ) {
                    $alias = $xmlLayerZero->xpath("aliases/alias[@field='".$v->filterFieldName."']");
                    if( count($alias) != 0 ) {
                        $alias = $alias[0];
                        $v->filterFieldAlias = (string)$alias['name'];
                        $configJson->locateByLayer->$k = $v;
                    }
                }
                // vectorjoins
                $vectorjoins = $xmlLayerZero->xpath('vectorjoins/join');
                if( count($vectorjoins) != 0 ) {
                    if ( !property_exists( $v, 'vectorjoins' ) )
                        $v->vectorjoins = array();
                    foreach( $vectorjoins as $vectorjoin ) {
                        $joinLayerId = (string)$vectorjoin['joinLayerId'];
                        if ( in_array($joinLayerId, $locateLayerIds ) )
                            $v->vectorjoins[] = (object) array(
                                "joinFieldName"=>(string)$vectorjoin['joinFieldName'],
                                "targetFieldName"=>(string)$vectorjoin['targetFieldName'],
                                "joinLayerId"=>(string)$vectorjoin['joinLayerId'],
                            );
                    }
                    $configJson->locateByLayer->$k = $v;
                }
            }
        }

        // Remove FTP remote directory
        if(property_exists($configJson->options, 'remoteDir'))
            unset($configJson->options->remoteDir);

        // Remove editionLayers from config if no right to access this tool
        // Or if no ability to load spatialite extension
        if ( property_exists( $configJson, 'editionLayers' ) ) {
            if( jAcl2::check('lizmap.tools.edition.use', $this->repository->getKey()) ){
                $spatial = false;
                if ( class_exists('SQLite3') ) {
                    $spatial = false;
                    // Try with libspatialite
                    try{
                        $db = new SQLite3(':memory:');
                        $spatial = $db->loadExtension('libspatialite.so'); # loading SpatiaLite as an extension
                    }catch(Exception $e){
                        $spatial = False;
                    }
                    // Try with mod_spatialite
                    if( !$spatial )
                        try{
                            $db = new SQLite3(':memory:');
                            $spatial = $db->loadExtension('mod_spatialite.so'); # loading SpatiaLite as an extension
                        }catch(Exception $e){
                            $spatial = False;
                        }
                }
                if(!$spatial){
                    foreach( $configJson->editionLayers as $key=>$obj ){
                        $layerXml = $this->getXmlLayer( $obj->layerId );
                        $layerXmlZero = $layerXml[0];
                        $provider = $layerXmlZero->xpath('provider');
                        $provider = (string)$provider[0];
                        if ( $provider == 'spatialite' )
                            unset($configJson->editionLayers->$key);
                    }
                }
            } else {
                unset($configJson->editionLayers);
            }
        }

        // Add export layer right
        if( jAcl2::check('lizmap.tools.layer.export', $this->repository->getKey()) ){
            $configJson->options->exportLayers = 'True';
        }

        // Update config with layer relations
        $relations = $this->getRelations();
        if( $relations )
            $configJson->relations = $relations;

        $WMSUseLayerIDs = $this->xml->xpath( "//properties/WMSUseLayerIDs" );
        if ( count($WMSUseLayerIDs) > 0 && $WMSUseLayerIDs[0] == 'true' ) {
            $configJson->options->useLayerIDs = 'True';
        }

        $configRead = json_encode($configJson);

        return $configRead;
    }

    public function getCanvasColor(){
        return $this->canvasColor;
    }

    protected function readCanvasColor($xml) {
        $red = $xml->xpath( "//properties/Gui/CanvasColorRedPart" );
        $green = $xml->xpath( "//properties/Gui/CanvasColorGreenPart" );
        $blue = $xml->xpath( "//properties/Gui/CanvasColorBluePart" );
        return 'rgb('.$red[0].','.$green[0].','.$blue[0].')';
    }

    public function getProj4( $authId ){
        return $this->xml->xpath( "//spatialrefsys/authid[.='".$authId."']/parent::*/proj4" );
    }

    public function getAllProj4( ) {
        return $this->allProj4;
    }

    protected function readAllProj4($xml) {
        $srsList = array();
        $spatialrefsys = $xml->xpath( "//spatialrefsys" );
        foreach ( $spatialrefsys as $srs ) {
            $srsList[ (string) $srs->authid ] = (string) $srs->proj4;
        }
        return $srsList;
    }

    public function getFullCfg(){
        return $this->cfg;
    }

    public function getXmlLayers(){
        return $this->xml->xpath( "//maplayer" );
    }

    public function getXmlLayer( $layerId ){
        return $this->xml->xpath( "//maplayer[id='$layerId']" );
    }

    public function getXmlLayerByKeyword( $key ){
        return $this->xml->xpath( "//maplayer/keywordList[value='$key']/parent::*" );
    }

    public function getComposer( $title ){
        $xmlComposer = $this->xml->xpath( "//Composer[@title='$title']" );
        if( $xmlComposer )
            return $xmlComposer[0];
        else
            return null;
    }

    public function getLayer( $layerId ){
        $xmlLayer = $this->xml->xpath( "//maplayer[id='$layerId']" );
        if( $xmlLayer ) {
            $xmlLayer = $xmlLayer[0];
            jClasses::inc('lizmap~qgisMapLayer');
            jClasses::inc('lizmap~qgisVectorLayer');
            if( $xmlLayer->attributes()->type == 'vector' )
                return new qgisVectorLayer( $this, $xmlLayer );
            else
                return new qgisMapLayer( $this, $xmlLayer );
        }
        return null;
    }

    public function getLayerByKeyword( $key ){
        $xmlLayer = $this->xml->xpath( "//maplayer/keywordList[value='$key']/parent::*" );
        if( $xmlLayer ) {
            $xmlLayer = $xmlLayer[0];
            jClasses::inc('lizmap~qgisMapLayer');

            jClasses::inc('lizmap~qgisVectorLayer');
            if( $xmlLayer->attributes()->type == 'vector' )
                return new qgisVectorLayer( $this, $xmlLayer );
            else
                return new qgisMapLayer( $this, $xmlLayer );
        }
        return null;
    }

    public function findLayersByKeyword( $key ){
        $xmlLayers = $this->xml->xpath( "//maplayer/keywordList[value='$key']/parent::*" );
        $layers = array();
        if( $xmlLayers ) {
            jClasses::inc('lizmap~qgisMapLayer');
            jClasses::inc('lizmap~qgisVectorLayer');
            foreach( $xmlLayers as $xmlLayer ) {
                if( $xmlLayer->attributes()->type == 'vector' )
                    $layers[] = new qgisVectorLayer( $this, $xmlLayer );
                else
                    $layers[] = new qgisMapLayer( $this, $xmlLayer );
            }
        }
        return $layers;
    }

    public function getRelations() {
        return $this->relations;
    }

    protected function readRelations($xml) {
        $xmlRelations = $xml->xpath( "//relations" );
        $relations = array();
        $pivotGather = array();
        $pivot = array();
        if( $xmlRelations ){
            foreach( $xmlRelations[0] as $relation ) {
                $relationObj = $relation->attributes();
                $fieldRefObj = $relation->fieldRef->attributes();
                if( !array_key_exists( (string)$relationObj->referencedLayer, $relations ) )
                    $relations[ (string)$relationObj->referencedLayer ] = array();

                $relations[ (string)$relationObj->referencedLayer ][] = array(
                    'referencingLayer' =>  (string)$relationObj->referencingLayer,
                    'referencedField' => (string)$fieldRefObj->referencedField,
                    'referencingField' => (string)$fieldRefObj->referencingField
                );

                if( !array_key_exists( (string)$relationObj->referencingLayer, $pivotGather ) )
                    $pivotGather[ (string)$relationObj->referencingLayer ] = array();

                $pivotGather[ (string)$relationObj->referencingLayer ][(string)$relationObj->referencedLayer] = (string)$fieldRefObj->referencingField;

            }

            // Keep only child with at least to parents
            foreach( $pivotGather as $pi=>$vo ){
                if( count( $vo ) > 1 ){
                    $pivot[$pi] = $vo;
                }
            }
            $relations['pivot'] = $pivot;

            return $relations;
        }
        else
            return null;
    }

    public function getDefaultDockable() {
        jClasses::inc('view~lizmapMapDockItem');
        $dockable = array();
        $bp = jApp::config()->urlengine['basePath'];

        // Get lizmap services
        $services = lizmap::getServices();

        // only maps
        if($services->onlyMaps) {
                $projectsTpl = new jTpl();
                $dockable[] = new lizmapMapDockItem(
                    'home',
                    jLocale::get('view~default.repository.list.title'),
                    $projectsTpl->fetch('view~map_projects'),
                    0
                );
        }

        $switcherTpl = new jTpl();
        $switcherTpl->assign(array(
            'layerExport'=>jAcl2::check('lizmap.tools.layer.export', $this->repository->getKey())
        ));
        $dockable[] = new lizmapMapDockItem(
            'switcher',
            jLocale::get('view~map.switchermenu.title'),
            $switcherTpl->fetch('view~map_switcher'),
            1
        );
        //$legendTpl = new jTpl();
        //$dockable[] = new lizmapMapDockItem('legend', 'Légende', $switcherTpl->fetch('map_legend'), 2);

        $metadataTpl = new jTpl();
        // Get the WMS information
        $wmsInfo = $this->getWMSInformation();
        // WMS GetCapabilities Url
        $wmsGetCapabilitiesUrl = jAcl2::check(
            'lizmap.tools.displayGetCapabilitiesLinks',
            $this->repository->getKey()
        );
        $wmtsGetCapabilitiesUrl = $wmsGetCapabilitiesUrl;
        if ( $wmsGetCapabilitiesUrl ) {
            $wmsGetCapabilitiesUrl = $this->getData('wmsGetCapabilitiesUrl');
            $wmtsGetCapabilitiesUrl = $this->getData('wmtsGetCapabilitiesUrl');
        }
        $metadataTpl->assign(array_merge(array(
            'repositoryLabel'=>$this->getData('label'),
            'repository'=>$this->repository->getKey(),
            'project'=>$this->getKey(),
            'wmsGetCapabilitiesUrl' => $wmsGetCapabilitiesUrl,
            'wmtsGetCapabilitiesUrl' => $wmtsGetCapabilitiesUrl
        ), $wmsInfo));
        $dockable[] = new lizmapMapDockItem(
            'metadata',
            jLocale::get('view~map.metadata.link.label'),
            $metadataTpl->fetch('view~map_metadata'),
            2
        );


        if ( $this->hasEditionLayers() ) {
            $tpl = new jTpl();
            $dockable[] = new lizmapMapDockItem(
                'edition',
                jLocale::get('view~edition.navbar.title'),
                $tpl->fetch('view~map_edition'),
                3,
                '',
                $bp.'js/edition.js'
            );
        }

        return $dockable;
    }

    public function getDefaultMiniDockable() {
        jClasses::inc('view~lizmapMapDockItem');
        $dockable = array();
        $configOptions = $this->getOptions();
        $bp = jApp::config()->urlengine['basePath'];

        if ( $this->hasLocateByLayer() ) {
            $tpl = new jTpl();
            $dockable[] = new lizmapMapDockItem(
                'locate',
                jLocale::get('view~map.locatemenu.title'),
                $tpl->fetch('view~map_locate'),
                1
            );
        }

        if ( property_exists($configOptions,'geolocation')
            && $configOptions->geolocation == 'True') {
            $tpl = new jTpl();
            $dockable[] = new lizmapMapDockItem(
                'geolocation',
                jLocale::get('view~map.geolocate.navbar.title'),
                $tpl->fetch('view~map_geolocation'),
                2
            );
        }

        if ( property_exists($configOptions,'print')
            && $configOptions->print == 'True') {
            $tpl = new jTpl();
            $dockable[] = new lizmapMapDockItem(
                'print',
                jLocale::get('view~map.print.navbar.title'),
                $tpl->fetch('view~map_print'),
                3
            );
        }

        if ( property_exists($configOptions,'measure')
            && $configOptions->measure == 'True') {
            $tpl = new jTpl();
            $dockable[] = new lizmapMapDockItem(
                'measure',
                jLocale::get('view~map.measure.navbar.title'),
                $tpl->fetch('view~map_measure'),
                4
            );
        }

        if ( $this->hasTooltipLayers() ) {
            $tpl = new jTpl();
            $dockable[] = new lizmapMapDockItem(
                'tooltip-layer',
                jLocale::get('view~map.tooltip.navbar.title'),
                $tpl->fetch('view~map_tooltip'),
                5,
                '',
                ''
            );
        }

        if ( $this->hasTimemanagerLayers() ) {
            $tpl = new jTpl();
            $dockable[] = new lizmapMapDockItem(
                'timemanager',
                jLocale::get('view~map.timemanager.navbar.title'),
                $tpl->fetch('view~map_timemanager'),
                6,
                '',
                $bp.'js/timemanager.js'
            );
        }

        // Permalink
        if ( true ) {
            // Get geobookmark if user is connected
            $gbCount = False; $gbList = Null;
            if( jAuth::isConnected() ){
                $juser = jAuth::getUserSession();
                $usr_login = $juser->login;
                $daogb = jDao::get('lizmap~geobookmark');
                $conditions = jDao::createConditions();
                $conditions->addCondition('login','=',$usr_login);
                $conditions->addCondition(
                    'map',
                    '=',
                    $this->repository->getKey().':'.$this->getKey()
                );
                $gbList = $daogb->findBy($conditions);
                $gbCount = $daogb->countBy($conditions);
            }
            $tpl = new jTpl();
            $tpl->assign( 'gbCount', $gbCount );
            $tpl->assign( 'gbList', $gbList );
            $gbContent = Null;
            if( $gbList )
                $gbContent = $tpl->fetch('view~map_geobookmark');
            $tpl = new jTpl();
            $tpl->assign('gbContent', $gbContent);
            $dockable[] = new lizmapMapDockItem(
                'permaLink',
                jLocale::get('view~map.permalink.navbar.title'),
                $tpl->fetch('view~map_permalink'),
                6
            );
        }

        return $dockable;
    }

    public function getDefaultBottomDockable() {
        jClasses::inc('view~lizmapMapDockItem');
        $dockable = array();
        $configOptions = $this->getOptions();
        $bp = jApp::config()->urlengine['basePath'];

        if ( $this->hasAttributeLayers() ) {
            $form = jForms::create( 'view~attribute_layers_option' );
            $assign = array( 'form' => $form );
            $tpl = new jTpl();
            $dockable[] = new lizmapMapDockItem(
                'attributeLayers',
                jLocale::get('view~map.attributeLayers.navbar.title'),
                array( 'view~map_attributeLayers', $assign ),
                1,
                '',
                $bp.'js/attributeTable.js'
            );
        }

        return $dockable;
    }

}
