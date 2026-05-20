<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR|E_ALL);
ini_set('display_errors', 'Off');

require_once ( 'php/Widar.php' );
require_once ( __DIR__.'/../scripts/wikistream.php' ) ;
require_once ( __DIR__.'/../scripts/ApiDispatcher.php' ) ;

$config = (new WikiStreamConfig)->get_config_instance();

# Try login etc
$tool = $config->toolkey;
$tool_url = "https://{$tool}.toolforge.org/";
$widar = new Widar($tool) ;
$widar->attempt_verification_auto_forward ( $tool_url ) ;
$widar->authorization_callback = "{$tool_url}api.php" ;
if ( $widar->render_reponse ( true ) ) exit ( 0 ) ;

# Actual action
$ws = new WikiStream($config,$widar->tfc);
$action = $ws->tfc->getRequest('action','');
$ws->language = preg_replace('|[^a-z-]|','',$ws->tfc->getRequest('language','en'));

$dispatcher = new ApiDispatcher($ws, $widar);
$result = $dispatcher->handle($action);

header("Cache-Control: {$result['cache_control']}");
http_response_code($result['http_code']);
header('Content-Type: application/json');
print json_encode($result['out']);
?>
