<?php

namespace Rypsx\Infomaniak;

use Rypsx\Infomaniak\FluxState;
use Rypsx\Infomaniak\LiveStats;
use Rypsx\Infomaniak\CurrentListeners;
use Carbon\Carbon;

// Geolocalize your listeners
use Rypsx\Ipapi\Ipapi;

class Infomaniak
{    
    /**
     * @var string
     */
    protected $login;

    /**
     * @var string
     */
    protected $passwd;

    /**
     * @var string
     */
    protected $rate;

    /**
     * @var string
     */
    protected $codec;

    /**
     * @var string
     */
    public $erreur;

    /**
     * @var Carbon
     */
    public $updateDate;

    /**
     * @var Object
     */
    public $flux;

    /**
     * @var Object
     */
    public $live;

    /**
     * @var Object
     */
    public $current;

    /**
     * @var int
     */
    private $counterIpApi;

    CONST LOGIN_INVALIDE    = "Nom d'utilisateur invalide";
    CONST PASSWD_INVALIDE   = "Mot de passe invalide";
    CONST RATE_INVALIDE     = "Débit invalide";
    CONST CODEC_INVALIDE    = "Codec invalide";
    CONST URL_INVALIDE      = "Impossible d'accéder à Infomaniak";
    CONST IPAPI_EXCEEDED    = "Au moins 149 requêtes ont été effectuées pour IP-API.com, ce qui en fait la limite gratuite par minute";

    /**
     * Instance de l'objet Infomaniak
     * @param string $login
     * @param string $passwd
     * @param string $rate
     * @param string $codec
     * @param bool   $sorted
     * @return void
     */
    public function __construct($login = null, $passwd = null, $rate = null, $codec = null, $sorted = false, $ipapi = false)
    {
        $this->counterIpApi = 0;
        $this->setLogin($login);
        $this->setPasswd($passwd);
        $this->setRate($rate);
        $this->setCodec($codec);
        $this->setUpdateDate($this->getDatetime());
        $this->fluxState();
        $this->liveStats();
        $this->currentListeners($sorted, $ipapi);
    }

    /**
     * Obtenir la date actuelle
     * @return Carbon Object
     */
    private function getDatetime()
    {
        $mytime = Carbon::now();
        return $mytime->toDateTimeString();
    }

    /**
     * Méthode permettant d'assigner le login
     * @param string $login
     */
    public function setLogin($login)
    {
        if (!is_string($login) || empty($login) || is_null($login)) {
        	$this->erreur = self::LOGIN_INVALIDE;
        } else {
            $this->login = $login;
        }
    }

    /**
     * Méthode permettant d'assigner le mot de passe
     * @param string $passwd
     */
    public function setPasswd($passwd)
    {
        if (!is_string($passwd) || empty($passwd) || is_null($passwd)) {
            $this->erreur = self::PASSWD_INVALIDE;
        } else {
            $this->passwd = $passwd;
        }
    }

    /**
     * Méthode permettant d'assigner le débit du flux
     * @param string $rate
     */
    public function setRate($rate)
    {
        if (empty($rate) || is_null($rate)) {
            $this->erreur = self::RATE_INVALIDE;
        } else {
            $this->rate = $rate;
        }
    }

    /**
     * Méthode permettant d'assigner le codec utilisé. Eg. mp3 / aac
     * @param string $codec
     */
    public function setCodec($codec)
    {
        if (!is_string($codec) || empty($codec) || is_null($codec)) {
            $this->erreur = self::CODEC_INVALIDE;
        } else {
            $this->codec = $codec;
        }
    }

    /**
     * Définit la date de mise à jour
     * @param  string $updateDate
     */
    public function setUpdateDate($updateDate)
    {
        $this->updateDate = $updateDate;
    }

    /**
     * Méthode permettant d'avoir le statut des flux en direct
     * @return void
     */
    private function fluxState()
    {
    	$principal = @file_get_contents('https://'.$this->login.':'.$this->passwd.'@statslive.infomaniak.com/radio/diag/status.php?mount=/'.$this->login.'-'.$this->rate.'.'.$this->codec.'');
    	$backup = @file_get_contents('https://'.$this->login.':'.$this->passwd.'@statslive.infomaniak.com/radio/diag/status.php?mount=/'.$this->login.'-'.$this->rate.'-bak.'.$this->codec.'');

        if ($principal === false || $backup === false) {
            throw new \Exception(self::URL_INVALIDE);
        }

        try {
            $this->flux = new FluxState(
                [
                    'principal' => $principal,
                    'backup'    => $backup,
                ]
            );
        } catch (\Exception $e) {
            $this->erreur = $e->getMessage();
        }
    }

    /**
     * Méthode permettant d'obtenir le nombre maximum d'auditeurs et celui actuel
     * @return void
     */
    private function liveStats()
    {
        $xml = 'https://'.$this->login.':'.$this->passwd.'@statslive.infomaniak.com/admin/stats.xml';
        try {
            if ($xml === false) {
                throw new \Exception(self::URL_INVALIDE);
            }

            $xml = simplexml_load_file($xml);
            $peak = $xml->source->listener_peak;
            $current = $xml->source->listeners;

            $this->live = new LiveStats(
                [
                    'peak'    => $peak,
                    'current' => $current
                ]
            );
        } catch (\Exception $e) {
            $this->erreur = $e->getMessage();
        }
    }

    /**
     * Méthode permettant d'obtenir les informations précises sur les auditeurs actuels
     * Possibilité de trier les résultats de façon décroissante
     * POssibilité d'obtenir les informations géographiques sur les auditeurs via IP-API.com
     * @return void
     */
    private function currentListeners($sorted, $ipApi)
    {
        $statsArray = array();
        $sortedArray = array();
        $xml = 'https://statslive.infomaniak.com/mediastats.php?radio='.$this->login.'-'.$this->rate.'.'.$this->codec.'&id='.$this->passwd;
        
        try {
            if ($xml === false) {
                throw new \Exception(self::URL_INVALIDE);
            }
            $xml = simplexml_load_file($xml);
            foreach ($xml->source->listener as $listener) {

                if ($ipApi) {
                    if ($this->counterIpApi < 150) {
                        $this->counterIpApi++;
                        $ipapiObject = new Ipapi((string) $listener->IP);
                    } else {
                        $ipapiObject = null;
                        $this->erreur = self::IPAPI_EXCEEDED;
                    }
                } else {
                    $ipapiObject = null;
                }

                $statsArray[(int) $listener->Connected] = new CurrentListeners(
                    [
                        'ip'          => $listener->IP,
                        'dureeEcoute' => $listener->Connected,
                        'ipApi'       => $ipapiObject
                    ]
                );
            }
            if ($sorted) {
                krsort($statsArray);
            }
            $this->current = $statsArray;
        } catch (\Exception $e) {
            $this->erreur = $e->getMessage();
        }        
    }
}
