<?php
/*!
    Buy-By-Touch catalogue generator

    Products are retrieved using PrestaShop Web Services API.
    Edit config.php and run from console then. 
    Output is written to catalogue.xml

    Author: Ondrej Pancocha <o.pancocha@centrum.cz>
*/

define('DEBUG', true);
define('TRACE', true);
define('FLUSH', true);
@ini_set('display_errors', 'on');

require_once('config.php');
require_once('PSWebServiceLibrary.php');

/*!
    Print debug string if the global DEBUG variable is true
    \param str String to print
*/
function dbg($str)
{
    if (DEBUG){
        print ("# ".$str."\n");
    }
    if (FLUSH){
        ob_flush();
    }
}

/*!
    Print content of a variable if the global DEBUG variable is true
    \param var Variable to display
*/
function dbgvar($var)
{
    if (DEBUG){
        print ("# ");
        var_dump($var);
    }
    if (FLUSH){
        ob_flush();
    }
}

/*!
    Print trace string if the global TRACE variable is true
    \param str String to print
*/
function trc($str)
{
    if (TRACE){
        print ("> ".$str."\n");
    }
    if (FLUSH){
        ob_flush();
    }
}

/*!
    Resolver of PS categories
*/
class psCategory
{
    /*! PrestaShopWebservice instance */
    private $ws = NULL;

    /*! selected language */
    private $langId = 1;

    /*! Categorires map */
    private $categories;    

    public function __construct($ws,$langId)
    {
        dbg(__METHOD__." langId=".$langId);
        $this->ws = $ws;
        $this->langId = $langId;
    }

    /*! Resolve category */
    public function resolveCategory($id)
    {
        global $BBC_CAT_IGNORE_RNAMES;
        if (isset($this->categories[$id])) {
            dbg("Category".$id." \"".$this->categories[$id]['rew']."\" is already resolved");
            return true;
        } else {
            dbg("Resolving category ".$id);
            $cat= $this->ws->get(array('url' => 'http://'.PS_SHOP_PATH.'/api/categories/'.$id));
            $catRName = (string) ($cat->xpath('category/link_rewrite/language[@id=\''.$this->langId.'\']')[0]);
            $catName = (string) ($cat->xpath('category/name/language[@id=\''.$this->langId.'\']')[0]);
            if (isset($catRName)) {
                dbg("Resolved: ".$id." -> ".$catRName);
                $this->categories[$id]['rew'] = $catRName;
                $this->categories[$id]['name'] = $catName;
                $this->categories[$id]['ign'] = false;
                // ignore category?
                foreach ($BBC_CAT_IGNORE_RNAMES as $cat_ignore_rname) {
                    if (!strcmp($this->categories[$id]['rew'], $cat_ignore_rname)) {
                        dbg('Ignoring category '.$cat_ignore_rname.' ('.$id.')');
                    $this->categories[$id]['ign'] = true;
                    }
                }
                return $catRName;
            } else {
                dbg("Failed to resolve category: ".$id);
                return NULL;
            }
        }
    }
    
    /*! Resolve and/or return cached category rewriten name */
    public function getRName($id)
    {
        if (!isset($this->categories[$id])) {
            // not cached yet, resolve the category
            if (!$this->resolveCategory($id)) {
                return NULL;
            }
        }
        dbg("Category ".$id." -> ".$this->categories[$id]['rew']);
        return $this->categories[$id]['rew'];
    }

    /*! Resolve and/or return cached category name */
    public function getName($id)
    {
        if (!isset($this->categories[$id])) {
            // not cached yet, resolve the category
            if (!$this->resolveCategory($id)) {
                return NULL;
            }
        }
        dbg("Category ".$id." -> ".$this->categories[$id]['name']);
        return $this->categories[$id]['name'];
    }

    /*! Resolve and/or return cached category ignore status */
    public function getIgnored($id)
    {
        if (!isset($this->categories[$id])) {
            // not cached yet, resolve the category
            if (!$this->resolveCategory($id)) {
                return NULL;
            }
        }
        return $this->categories[$id]['ign'];
    }
}

/*!
    Resolver of VAT rules
*/
class psVat
{
    /*! PrestaShopWebservice instance */
    private $ws = NULL;

    /*! selected language */
    private $langId = 1;

    /*! Tax rule group -> tax id map */
    private $taxRG;    

    public function __construct($ws,$langId)
    {
        dbg(__METHOD__." langId=".$langId);
        $this->ws = $ws;
        $this->langId = $langId;
    }

    /*! Get tax rate
        \param idRG Id of tax rules group which the tax belong to
     */
    public function getRate($idRG)
    {
    //    dbgvar($this->taxRG[$idRG]);
        if (isset($this->taxRG[$idRG]['rate'])) {
            dbg("Tax RG=".$idRG.
                ", taxId=".$this->taxRG[$idRG]['id'].
                ", rate=".$this->taxRG[$idRG]['rate']);
            return $this->taxRG[$idRG]['rate'];
        } else {
            dbg("Tax RG ".$idRG." is unknown");
            return NULL;
        }
    }

    /*! iterate all tax_rules items and remember tax RG - tax pairs */
    public function setup()
    {
        // get tax id for each tax rules group
        dbg("Fetching tax rule groups");
        $tax_rules = $this->ws->get(array('resource' => 'tax_rules','display' => 'full'));
        foreach ($tax_rules->tax_rules->tax_rule as $tax_rule ) {
            $idRG = (string) $tax_rule->id_tax_rules_group;
            $idTax = (string) $tax_rule->id_tax;
            if (!isset($this->taxRG[$idRG])) {
                //this RG is not in the map yet
                $this->taxRG[$idRG]['id'] = $idTax;
                dbg("idRG ".$idRG." -> idTax ".$idTax);
            }
        }

        // resolve tax rates
        dbg("Resolving tax values");
        foreach ($this->taxRG as $idRG => $taxRec) {
            $taxXML = $this->ws->get(array('url' => 'http://'.PS_SHOP_PATH.'/api/taxes/'.$taxRec['id']));
//            $taxRec['rate'] = (string) $taxXML->tax->rate;
            $this->taxRG[$idRG]['rate'] = (string) $taxXML->tax->rate;
            dbg("Tax id=".$this->taxRG[$idRG]['id'].", rate=".$this->taxRG[$idRG]['rate']);
        }
    }
    
}

/*!
    Product option values
*/
class psProductOptionValues
{
    public $id;
    public $name;
}
/*!
    One product combination
*/
class psCombination
{
    public $id;
    public $reference;
    public $price;
    public $priceImpact;
    public $weight;
    public $productOV;
}

/*!
    Resolver of product combinations
*/
class psCombinationResolver
{
    /*! PrestaShopWebservice instance */
    private $ws = NULL;

    /*! selected language */
    private $langId = 1;

    /*! comb id -> psCombination map */
    private $combMap;    

    /*! prod option values id -> psProductOptionValues map */
    private $prodOVMap;

    public function __construct($ws,$langId)
    {
        dbg(__METHOD__." langId=".$langId);
        $this->ws = $ws;
        $this->langId = $langId;
    }

    /*! Get combination
        \param id Combination id
        \return psCombination corresponding to the id
     */
    public function getCombination($id)
    {
        if (isset($this->combMap[$id])) {
            dbg("Combination id=".$id." is already resolved");
            return $this->combMap[$id];
        } else {
            if ($this->resolveCombination($id)) {
                return $this->combMap[$id];
            } else {
                dbg("Failed to resolve combination ".$id);
                return NULL;
            }
        }
    }

    /*! Get Product Option Values
        \param id prod OV id
        \return psProductOptionValues corresponding to the id
     */
    public function getProdOV($id)
    {
        if (isset($this->prodOVMap[$id])) {
            dbg("ProdOV id=".$id." is already resolved");
            return $this->prodOVMap[$id];
        } else {
            if ($this->resolveProdOV($id)) {
                return $this->prodOVMap[$id];
            } else {
                dbg("Failed to resolve ProdOV ".$id);
                return NULL;
            }
        }
    }

    /*! Resolve ProdOV and store it to the prodOVMap
        \param id ProdOV id
        \return true/false
     */
    protected function resolveProdOV($id)
    {
        dbg("Resolving ProdOV ".$id);
        $prodOVXML= $this->ws->get(array('url' => 'http://'.PS_SHOP_PATH.'/api/product_option_values/'.$id));
        $prodOV = new psProductOptionValues();
        $prodOV->id = $id;
        $prodOV->name = (string)($prodOVXML->xpath('product_option_value/name/language[@id=\''.$this->langId.'\']')[0]);
        $this->prodOVMap[$id] = $prodOV;
        return true;
    }

    /*! Resolve combination and store it to the combMap
        \param id Combination id
        \return true/false
     */
    protected function resolveCombination($id)
    {
        dbg("Resolving combination ".$id);
        $combXML= $this->ws->get(array('url' => 'http://'.PS_SHOP_PATH.'/api/combinations/'.$id));
        $comb = new psCombination();
        $comb->id = $id;
        $comb->reference = (string) $combXML->combination->reference;
        $comb->price = (string) $combXML->combination->price;
        $comb->priceImpact = (string) $combXML->combination->unit_price_impact;
        $comb->weight = (string) $combXML->combination->weight;
        $prodOVId = (string) $combXML->combination->associations->product_option_values->product_option_value->id;
        $comb->productOV = $this->getProdOV($prodOVId);
        $this->combMap[$id] = $comb;
        return true;
    }
}

/*!
    Add one catalogue item to the catalogue XML.
    \param out[out]     output SimpleXMLElement
    \param ws[in]       PrestaShopWebservice instance
    \param p[in]        One product retrieved from the PrestaShop WS API
    \param langId[in]   Id of the language used for descriptions
*/
function addCItem($out, $ws, $p, $langId)
{
//    dbg(__FUNCTION__);
//    dbg("adding product:".$p->asXML());
    
    global $combRes;
    global $cres;
    global $vat;
    global $BBC_REF_IGNORE_PATTERNS;

    $id=(string)$p->product->id;
    $reference=(string)$p->product->reference;
    if (!(bool)((string)$p->product->active)) {
        dbg("Product ".($p->xpath('description/language[@id=\''.$langId.'\']')[0])." is not active");
        return NULL;
    }
    // ignore product?
    foreach ($BBC_REF_IGNORE_PATTERNS as $ref_ignore_pattern) {
        if (preg_match("/".$ref_ignore_pattern."/",$reference)) {
            trc("Ignoring product, because it's refcode '".$reference.
                "' matches ignore pattern '".$ref_ignore_pattern."'");
            return NULL;
        } 
    }
    $manufacturer =(string) $p->product->manufacturer_name;
    $description = strip_tags((string) ($p->xpath('description/language[@id=\''.$langId.'\']')[0]));
    $nameRewrite = (string)($p->xpath('link_rewrite/language[@id=\''.$langId.'\']')[0]);
    $defCatId = (string) $p->product->id_category_default;
    $defCatRName = $cres->getRName($defCatId);
    $url = 'http://'.PS_SHOP_PATH.'/'.$defCatRName.'/'.$id.'-'.$nameRewrite.'.html';
    $name = (string) ($p->xpath('name/language[@id=\''.$langId.'\']')[0]);
    $imgId = (string) $p->product->id_default_image;
    $imgUrl = 'http://'.PS_SHOP_PATH.'/img/p/';
    for ($i = 0; $i < strlen($imgId); $i++) {
        $imgUrl .= $imgId[$i] . '/';
    }
    $imgUrl .= $imgId . '.jpg';
    $price = 0;
    $combIdXml = NULL;
    $hasComb = $p->product->associations->combinations->children()->count();
    $combArray = NULL;

    if ($hasComb) {
        // product has combinations, add each of them as one package
        dbg("Product uses combinations (".$p->product->associations->combinations->children()->count().")");
        $combArray = $p->product->associations->combinations->children();
        $combIdXml = $combArray[0];
        $i=0;
    }

    // output item(s)
    do {
        $idTaxRG = (string) $p->product->id_tax_rules_group;
        $vatRate = $vat->getRate($idTaxRG);
        $priceNoVat = ((string) $p->product->price)/(1+($vatRate/100));

        // If the combinations are used, modify related product attributes with
        // the combination attributes. Each combination is presented as
        // independent product
        if ($hasComb) {
            $combId = (string) $combIdXml->id;
            $comb = $combRes->getCombination($combId);

            //dbgvar($comb);
            if ($comb->reference != "") {
                $reference = $comb->reference;
            }
            $priceNoVat += $comb->price;
            // reinitialize name to discard suffix appended in previous cycle
            $name = (string) ($p->xpath('name/language[@id=\''.$langId.'\']')[0]);
            $name .= ", ".$comb->productOV->name;
        }

        dbg("Price:".$p->product->price.", VAT:".$vatRate.", price_no_VAT:".$priceNoVat);

        // create shop item
        $item=$out->addChild('shopitem');
        $item->addAttribute('id',$reference);
        $item->addAttribute('name',$name);
        $item->addAttribute('description',$description);
        $item->addAttribute('manufacturer',$manufacturer);
        $item->addAttribute('url',$url);
        $item->addAttribute('imgurl',$imgUrl);

        // add package
        $pkg = $item->addChild('package');
        $pkg->addAttribute('id',$reference);
        $pkg->addAttribute('count',1);
        $pkg->addAttribute('price',round($priceNoVat,3));
        // output VAT rate as float (not percents) e.g. 0.21
        $pkg->addAttribute('vat',$vatRate/100);

        // add all categories of the product
        foreach ($p->product->associations->categories->category as $catId) {
            $catName = $cres->getName((string)$catId->id);
            $ignored = $cres->getIgnored((string)$catId->id);
            if (isset($catName) && (!$ignored)) {
                dbg("adding category ".$catId->id);
                $cat = $item->addChild('category');
                $cat->addAttribute('name',$catName);
            }
        }

        
        trc("Added id=".$id.", name=".$name.", price=".$priceNoVat.", url=".$url);
        $combIdXml = $combArray[++$i];
        //print($combIdXml->asXML());
    } while ($hasComb && ($i < count($combArray)));
}

try
{

	ob_start();
    try 
    {
        $cat = simplexml_load_file('cat_template_1.9.xml');

        echo "<html><body><pre>";

        // creating web service access
        $ws = new PrestaShopWebservice(PS_SHOP_PATH, PS_WS_AUTH_KEY, false);
        trc("PS WebService created");
        $cres = new psCategory($ws,PS_ID_LANG);
        $vat = new psVat($ws,PS_ID_LANG);
        $combRes = new psCombinationResolver($ws,PS_ID_LANG);
        $vat->setup();

        //$xml = $ws->get(array('resource' => 'products','display' => 'full'));
        $xml = $ws->get(array('resource' => 'products'));
//        dbg($xml->children()->asXML());
        $prodCnt=1;
        foreach($xml->children()->children() as $product_url) {
//           if ($product_url['id'] != 162) {
//               continue;
//           }
            trc("Processing product ".$prodCnt);
            $prodCnt++;
//            $product_url = $xml->children()->children()[5];
//            dbgvar($product_url);
            dbg("product id=".$product_url['id']);
            $xml = $ws->get(array('url' => 'http://'.PS_SHOP_PATH.'/api/products/'.$product_url['id']));
//            dbg($xml->asXML());
            addCItem($cat, $ws, $xml->children(), PS_ID_LANG);
        }
        //$xml = $ws->get(array('url' => 'http://'.PS_SHOP_PATH.'/api/products/158'));
        //var_dump( $xml->children('description')->asXML());
        file_put_contents("catalogue.xml",$cat->asXML());
        echo "</pre></body></html>";
    }

    catch ( PrestaShopWebserviceException $ex ) 
    {
        header('HTTP/1.1 500 Internal Server Error', true, 500);
        $trace = $ex->getTrace(); // Retrieve all information on the error
        $errorCode = $trace[ 0 ][ 'args' ][ 0 ]; // Retrieve the error code
        if ( $errorCode == 401 )
            die('Bad auth key');
        else
            die( 'Other error : <br />' . $ex->getMessage() ); // Shows a message related to the error
    }

}
catch ( Exception $ex ) 
{	
	ob_end_clean();
	header('HTTP/1.1 500 Internal Server Error', true, 500);
}
?>
