<?php
	error_reporting(-1);
	ini_set('display_errors', 'On');
	include_once "classes/totp.class.php";

	$query = explode("=", $_SERVER['QUERY_STRING']);
	$wakeMessage = "";
	switch($query[0]){
		case "wake":
			if(preg_match("/([a-fA-F0-9]{2}[:|\-]?){6}/", $_GET["wake"])) {
				shell_exec("wakeonlan ".$_GET["wake"]);
				$wakeMessage = " ".Language::getString()['TRY_TO_WAKE_UP']." <strong>".$_GET["wake"].'</strong>.';
			} else {
				$wakeMessage = " ".Language::getString()['INVALID_MAC'];
			}
		break;
	}

	class Language {
		private static $lang;
		private static $initialized = false;

		private static function initialize() {
			if (self::$initialized)
				return;
			self::$initialized = true;
			switch (Configuration::getLang()) {
				case 'cs':
					$lang_file = 'lang.cs.php';
					break;
				case 'en':
					$lang_file = 'lang.en.php';
					break;
				default:
					$lang_file = 'lang.en.php';
			}
			include_once 'lang/'.$lang_file;
			self::$lang = $lang;
        }

		public static function getString() {
			self::initialize();
			return self::$lang;
		}
	}

	class IP {
		private $octets;

		function getPrettyIP() {
			return "<span class='ip'>".$this->octets[0].'.'.$this->octets[1].'.'.$this->octets[2].".</span>".$this->octets[3];
		}

		function __construct($e) {
			$this->octets = explode('.', $e);
		}
	}

	class LeaseDatetime {
		private $datetime;

		function getPrettyDate() {
			switch(Configuration::getLang()) {
				case "en":
					return "<time datetime='".Date("Y-m-d H:i:s", $this->datetime)."'>".Date("Y-m-d H:i:s", $this->datetime)."</time>";
				case "cs":
				$months = array("January","February","March","April","May","June","July","August","September","October","November","December");
				return "<time datetime='".Date("Y-m-d H:i:s", $this->datetime)."'>".str_replace($months, Language::getString()['MONTHS'],Date("j. F <\s\p\a\\n \c\l\a\s\s='\h\i\d\\e'>Y H:i:s</\s\p\a\\n>", $this->datetime))."</time>";
			}
		}

		function __construct($e) {
			$this->datetime = strtotime(substr($e,2));
		}
	}

	class Configuration {
		private static $configuration;
		private static $appdata;
		private static $initialized = false;

		private static function initialize() {
			if (self::$initialized)
        		return;
        	self::$initialized = true;
        	self::$configuration = parse_ini_file("config.ini", true);
        	self::$appdata = json_decode(file_get_contents("appdata.json"), true);
        }

        private static function saveConfiguration() {
			$file = fopen('appdata.json', 'w');
			fwrite($file, json_encode(self::$appdata, JSON_PRETTY_PRINT));
			fclose($file);
        }

		public static function getDevices() {
			self::initialize();
			return self::$configuration["devices"];
		}
		public static function getNetworkName() {
			self::initialize();
			return self::$configuration["user-config"]["network-name"];
		}
		public static function getNIC() {
			self::initialize();
			return self::$configuration["user-config"]["network-interface"];
		}
		public static function getSortSwitch() {
			self::initialize();
			return self::$configuration["user-config"]["sort-dynamic-addresses"];
		}
		public static function getConfigPath() {
			self::initialize();
			return self::$configuration["user-config"]["dhcp-config-path"];
		}
		public static function getLeasesPath() {
			self::initialize();
			return self::$configuration["user-config"]["dhcp-leases-path"];
		}
		public static function getExcludeFirstHost() {
			self::initialize();
			return self::$configuration["user-config"]["exclude-first-host"];
		}
		public static function getLang() {
			self::initialize();
			return self::$configuration["user-config"]["language"];
		}
		public static function getPrivateKey() {
			self::initialize();
			return self::$configuration["user-config"]["private-key"];
		}
		public static function getPassword() {
			self::initialize();
			return self::$configuration["user-config"]["password"];
		}
		public static function getTOTPsetting() {
			self::initialize();
			return self::$configuration["user-config"]["require-totp"];
		}
		public static function getPASSsetting() {
			self::initialize();
			return self::$configuration["user-config"]["require-password"];
		}

		public static function updateArpCache($arp) {
			self::initialize();
			self::$appdata["arp-cache"] = array_merge(self::$appdata["arp-cache"],$arp);
			self::saveConfiguration();
		}
	}

	class ArpScan {
		private static $table = [];
		private static $initialized = false;

		private static function initialize() {
			if (self::$initialized)
				return;
			self::$initialized = true;
			$arp = implode("\n", array_slice(explode("\n", shell_exec("sudo arp-scan --interface=".Configuration::getNIC()." --localnet")), 2, -4));

			$arpcache = array();
			foreach(explode("\n", $arp) as $ar) { 
				$line = explode("\t", $ar);
				self::$table[$line[0]] = $line[1];
				$arpcache[$line[0]]["MAC"] = $line[1];
				$arpcache[$line[0]]["timestamp"] = time();
			}

			Configuration::updateArpCache($arpcache);
		}

		public static function connectedDevices() {
			self::initialize();
			return count(self::$table)+1;
		}

		public static function getTable() {
			self::initialize();
			return self::$table;
		}
	}

	abstract class Addresses {
		protected $table;
		protected $remove = array("\"",";","{");
		protected $connectedDevices = 0;
		//protected $properties;

		abstract protected function getContent();
		protected function initializeArray() {
			$array = [];
			foreach($this->properties as $property) {
				if(!array_key_exists($property, $array))
					$array[$property] = "-";
			}
			return $array;
		}
		public function getConnectedDevices() {
			return $this->connectedDevices;
		}
	}

	class StaticAddresses extends Addresses {
		protected $properties = array("hardware ethernet", "host", "fixed-address");

		function getContent() {
			$serverIP = new IP(shell_exec("echo `ifconfig ".Configuration::getNIC()." 2>/dev/null|awk '/inet addr:/ {print $2}'|sed 's/addr://'`"));

			$out = "<tr><td>".gethostname()."</td><td>".$serverIP->getPrettyIP()."<td>"
				 .shell_exec("ifconfig ".Configuration::getNIC()." | grep -o -E '([[:xdigit:]]{1,2}:){5}[[:xdigit:]]{1,2}'")
				 ."</td><td class='on'>".Language::getString()['CONNECTED']."</td><td></td></tr>";
			$i=0;
			foreach($this->table as $key => $element){
				if($i != 0 OR !Configuration::getExcludeFirstHost()) {
					$hostIP = new IP($element["fixed-address"]);

					$out .= "<tr><td>".$element["host"]."</td><td>".$hostIP->getPrettyIP()."</td><td>".$element["hardware ethernet"]."</td>";
					if(array_key_exists($element["fixed-address"], ArpScan::getTable())) {
						$this->connectedDevices++;
						$out .= (ArpScan::getTable()[$element["fixed-address"]] == $element["hardware ethernet"] ? "<td class='on'>"
							 .Language::getString()['CONNECTED']."</td><td>" : "<td class='off'>".Language::getString()['DISCONNECTED']
							 ."</td><td><a href='?wake=".$element["hardware ethernet"]."'>".Language::getString()['WAKE_UP']."</a>");
					} else {
						$out .= "<td class='off'>".Language::getString()['DISCONNECTED']."</td><td><a href='?wake=".$element["hardware ethernet"]
							 ."'>".Language::getString()['WAKE_UP']."</a>";
					}
					$out .= "</td></tr>\n";
				}
			    $i++;
			}

			return $out;
		}

		function __construct() {
			$configFile = fopen(Configuration::getConfigPath(), "r") or die("Unable to open DHCP config file.");
			$i = 0;
			$ack = false;
			while (!feof($configFile)) {
				$readLine = trim(fgets($configFile, 4096));
				if (substr($readLine, 0, 1) != "#") {
					$tok = strtok($readLine, " ");
					switch($tok) {
						case "hardware":
							strtok(" ");
							$tok = "hardware ethernet";
						case "host":
						case "fixed-address":
							$this->table[$i][$tok] = str_replace(";","",strtok(" "));
							$ack = true;
						break;
						case '}':
							if($ack) {
								array_merge($this->initializeArray(),$this->table[$i]);
								$ack = false;
							}
							$i++;
						break;
					}
				}
			}
			fclose($configFile);
		}
	}

	class DynamicAddresses extends Addresses {
		protected $properties = array("lease", "starts", "ends", "client-hostname", "hardware ethernet");

		function getContent() {
			$devices = Configuration::getDevices();
			$out = "";

			foreach($this->table as $key => $element){
				$starts = new LeaseDatetime($element["starts"]);
				$ends = new LeaseDatetime($element["ends"]);
				$hostIP = new IP($element["lease"]);

				$out .= "<tr><td>".(array_key_exists($element["hardware ethernet"], $devices) ? $devices[$element["hardware ethernet"]] : $element["client-hostname"])
					 ."</td><td>".$hostIP->getPrettyIP()."</td><td>".$element["hardware ethernet"]."</td><td>".$starts->getPrettyDate()."</td><td>".$ends->getPrettyDate()."</td>";
				if(array_key_exists($element["lease"], ArpScan::getTable())) {
					$this->connectedDevices++;
					$out .= (ArpScan::getTable()[$element["lease"]] == $element["hardware ethernet"] ? "<td><p class='text-success'>".Language::getString()['CONNECTED'] : "<td><p class='text-muted'>".Language::getString()['DISCONNECTED']);
				} else {
					$out .= "<td><p class='text-muted'>".Language::getString()['DISCONNECTED'];
				}
				$out .= "</p></td></tr>";//<td><a href='?remove=".$element["lease"]."'>".Language::getString()['REVOKE']."</a></td></tr>";
			}

			return $out;
		}

		function __construct() {
			$leasesFile = fopen(Configuration::getLeasesPath(), "r") or die("Unable to open DHCP leases file.");
			while (!feof($leasesFile)) {
				$readLine = trim(fgets($leasesFile, 4096));
				if (substr($readLine, 0, 1) != "#") {
					$tok = strtok($readLine, " ");
					switch($tok) {
						case "lease":
							$device = strtok(" ");
							$this->table[$device] = $this->initializeArray();
							$this->table[$device]["lease"] = $device;
						break;
						case "hardware":
							strtok(" ");
							$tok = "hardware ethernet";
						case "starts":
						case "ends":
						case "client-hostname":
							$this->table[$device][$tok] = str_replace(array(';','\'', '"'),"",strtok(''));
						break;
						break;
					}
				}
			}
			fclose($leasesFile);
			if(Configuration::getSortSwitch())
				usort($this->table, function($a, $b) {
					return strcmp($a["lease"], $b["lease"]);
				});
		}
	}

	session_start();
	if(!isset($_SESSION['logged'])) {
		$_SESSION['logged'] = false;
	}

	$totpResult = false; $passResult = false;

	if(isset($_POST["totp"]) AND Configuration::getTOTPsetting()) {
		$totp = htmlspecialchars($_POST["totp"]);
		$totpResult = Google2FA::verify_key(Configuration::getPrivateKey(), $totp);
	}
	if(isset($_POST["password"]) AND Configuration::getPASSsetting()) {
		if($_POST["password"] == Configuration::getPassword()) {
			$passResult = true;
		} else {
			$passResult = false;
		}
	}

	if((((Configuration::getTOTPsetting() AND $totpResult) OR!Configuration::getTOTPsetting()) AND 
		((Configuration::getPASSsetting() AND $passResult) OR !Configuration::getPASSsetting()))) {
		$_SESSION['logged'] = true;
		$staticAddresses = new StaticAddresses();
		$dynamicAddresses = new DynamicAddresses();
	}
?>
<!doctype html>
<html>
	<head>
		<meta charset="utf-8">
		<title>DHCP server</title>
		<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=0">
		<meta name="mobile-web-app-capable" content="yes">
		<meta name="theme-color" content="#00325A">
		<link rel="stylesheet" href="bootstrap.min.css">
		<link rel="stylesheet" href="styles.css">
		<link rel="icon" type="image/png" sizes="192x192" href="images/icon-highres.png">
	</head>
	<body>
		<nav class="navbar navbar-inverse bg-inverse mb-4">
			<div class="container d-flex justify-content-between">
				<a class="navbar-brand" href="/"><?php echo Configuration::getNetworkName(); ?></a>
			</div>
		</nav>
		<div class="container">
			<?php
				if(!$_SESSION['logged']):
			?>
			<form action="" method="post">
				<?php if(Configuration::getPASSsetting()): ?>
				<div class="group">
					<input type="password" name="password" required>
					<span class="highlight"></span>
					<span class="bar"></span>
					<label><? echo Language::getString()['ENTER_PASS']; ?></label>
				</div>
				<?php endif; 
				if(Configuration::getTOTPsetting()):?>
				<div class="group">
					<input type="text" name="totp" maxlength="6" required>
					<span class="highlight"></span>
					<span class="bar"></span>
					<label><? echo Language::getString()['ENTER_TOTP']; ?></label>
				</div>
				<?php endif; ?>
				<input type="submit" value="<? echo Language::getString()['ENTER_PASS']; ?>">
			</form>
		</div>
	</body>
</html>
			<?php exit; endif; ?>
			<section class="jumbotron text-center">
				<div class="container">
					<h1 class="jumbotron-heading"><?php
					echo Language::getString()['OK_TEXT']
						 .$wakeMessage;
					?></h1>
					<p class="lead text-muted"><?php
					$hiddenDevices = $staticAddresses->getConnectedDevices() + $dynamicAddresses->getConnectedDevices();
					echo Language::getString()['CUR_CONN']
						 ." <strong>".ArpScan::connectedDevices()
						 ." "
						 .Language::getString()['DEVICES']
						 ."</strong>."
						 .($hiddenDevices > ArpScan::connectedDevices() ? 
							" ".Language::getString()['WHILE']." <strong>".$hiddenDevices."</strong> ".Language::getString()['HIDDEN'] : "");
					?></p>	
				</div>
			</section>
			<div class="row">
				<div class="col">
					<div class="card">
						<div class="card-block">
							<h4 class="card-title"><?php echo Language::getString()['STATIC']; ?></h4>
							<table class="table table-responsive">
								<?php 
									echo "<tr><th>"
										 .Language::getString()['DEVICE']
										 ."</th><th>IP ".Language::getString()['ADDR']
										 ."</th><th>MAC ".Language::getString()['ADDR']
										 ."</th><th>".Language::getString()['STATE']
										 ."</th><th>Wake on LAN</th></tr>";
									echo $staticAddresses->getContent();
								?>
							</table>
						</div>
					</div>
				</div>
			</div>
			<div class="row mt-4">
				<div class="col">
					<div class="card">
						<div class="card-block">
							<h4 class="card-title"><?php echo Language::getString()['DYNAMIC']; ?></h4>
							<table class="table table-responsive">
								<?php
									echo "<tr><th>".Language::getString()['DEVICE']
										 ."</th><th>IP ".Language::getString()['ADDR']
										 ."</th><th>MAC ".Language::getString()['ADDR']
										 ."</th><th>".Language::getString()['START']
										 ."</th><th>".Language::getString()['END']
										 ."</th><th>".Language::getString()['STATE']
										 ."</th></tr>";//<th>".Language::getString()['LEASE']."</th></tr>";"
									echo $dynamicAddresses->getContent();
								?>
							</table>
						</div>
					</div>
				</div>
			</div>
		</div>
		
	</body>
</html>
