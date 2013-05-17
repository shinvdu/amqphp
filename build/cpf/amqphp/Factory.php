<?php
 namespace amqphp; use amqphp\protocol; use amqphp\wire; use \amqphp\persistent as pers; class Factory { const XML_FILE = 1; const XML_STRING = 2; private static $RC_CACHE = array(); private $simp; private $rootEl; function __construct ($xml, $documentURI=false, $flag=self::XML_FILE) { $d = new \DOMDocument; switch ($flag) { case self::XML_FILE: if (! $d->load($xml)) { throw new \Exception("Failed to load factory XML", 92656); } break; case self::XML_STRING: if (! $d->loadXML($xml)) { throw new \Exception("Failed to load factory XML", 92656); } if ($documentURI) { $d->documentURI = $documentURI; } break; default: throw new \Exception("Invalid construct flag", 95637); } if (-1 === $d->xinclude()) { throw new \Exception("Failed to load factory XML", 92657); } else if (! ($this->simp = simplexml_import_dom($d))) { throw new \Exception("Failed to load factory XML", 92658); } switch ($tmp = strtolower((string) $this->simp->getName())) { case 'setup': case 'methods': $this->rootEl = $tmp; break; default: throw new \Exception("Unexpected Factory configuration data root element", 17893); } } function run (Channel $chan=null) { switch ($this->rootEl) { case 'setup': return $this->runSetupSequence(); case 'methods': if (is_null($chan)) { throw new \Exception("Invalid factory configuration - expected a target channel", 15758); } return $this->runMethodSequence($chan, $this->simp->xpath('/methods/method')); } } function getConnections () { $r = array(); foreach ($this->run() as $res) { if ($res instanceof Connection) { $r[] = $res; } } return $r; } private function callProperties ($subj, $conf) { foreach ($conf->xpath('./set_properties/*') as $prop) { $pname = (string) $prop->getName(); $pval = $this->kast($prop, $prop['k']); $subj->$pname = $pval; } } private function runSetupSequence () { $ret = array(); foreach ($this->simp->connection as $conn) { $_chans = array(); $refl = $this->getRc((string) $conn->impl); $_conn = $refl->newInstanceArgs($this->xmlToArray($conn->constr_args->children())); $this->callProperties($_conn, $conn); $_conn->connect(); $ret[] = $_conn; if (count($conn->exit_strats) > 0) { foreach ($conn->exit_strats->strat as $strat) { call_user_func_array(array($_conn, 'pushExitStrategy'), $this->xmlToArray($strat->children())); } } if ($_conn instanceof pers\PConnection && $_conn->getPersistenceStatus() == pers\PConnection::SOCK_REUSED) { continue; } foreach ($conn->channel as $chan) { $_chan = $_conn->openChannel(); $this->callProperties($_chan, $chan); if (isset($chan->event_handler)) { $impl = (string) $chan->event_handler->impl; if (count($chan->event_handler->constr_args)) { $refl = $this->getRc($impl); $_evh = $refl->newInstanceArgs($this->xmlToArray($chan->event_handler->constr_args->children())); } else { $_evh = new $impl; } $_chan->setEventHandler($_evh); } $_chans[] = $_chan; $rMeths = $chan->xpath('.//method'); if (count($rMeths) > 0) { $ret[] = $this->runMethodSequence($_chan, $rMeths); } if (count($chan->confirm_mode) > 0 && $this->kast($chan->confirm_mode, 'boolean')) { $_chan->setConfirmMode(); } } $i = 0; foreach ($conn->channel as $chan) { $_chan = $_chans[$i++]; foreach ($chan->consumer as $cons) { $impl = (string) $cons->impl; if (count($cons->constr_args)) { $refl = $this->getRc($impl); $_cons = $refl->newInstanceArgs($this->xmlToArray($cons->constr_args->children())); } else { $_cons = new $impl; } $this->callProperties($_cons, $cons); $_chan->addConsumer($_cons); if (isset($cons->autostart) && $this->kast($cons->autostart, 'boolean')) { $_chan->startConsumer($_cons); } } } } return $ret; } private function runMethodSequence (Channel $chan, array $meths) { $r = array(); foreach ($meths as $iMeth) { $a = $this->xmlToArray($iMeth); $c = $a['a_class']; $r[] = $chan->invoke($chan->$c($a['a_method'], $a['a_args'])); } return $r; } private function kast ($val, $cast) { switch ($cast) { case 'string': return (string) $val; case 'bool': case 'boolean': $val = trim((string) $val); if ($val === '0' || strtolower($val) === 'false') { return false; } else if ($val === '1' || strtolower($val) === 'true') { return true; } else { trigger_error("Bad boolean cast $val - use 0/1 true/false", E_USER_WARNING); return true; } case 'int': case 'integer': return (int) $val; case 'const': return constant((string) $val); case 'eval': return eval((string) $val); default: trigger_error("Unknown Kast $cast", E_USER_WARNING); return (string) $val; } } private function xmlToArray (\SimpleXmlElement $e) { $ret = array(); foreach ($e as $c) { $ret[(string) $c->getName()] = (count($c) == 0) ? $this->kast($c, (string) $c['k']) : $this->xmlToArray($c); } return $ret; } private function getRc ($class) { return array_key_exists($class, self::$RC_CACHE) ? self::$RC_CACHE[$class] : (self::$RC_CACHE[$class] = new \ReflectionClass($class)); } }