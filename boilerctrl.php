#!/usr/bin/php
<?php
$debug = true;
//$filename = "/tmp/ACTsdm630.txt";
openlog('BOILERCTRL', LOG_CONS | LOG_NDELAY | LOG_PID, LOG_USER | LOG_PERROR);

//open sh mem obj for reading actual values
$sh_sdm6301 = shmop_open(0x6301, "a", 0, 0);
if (!$sh_sdm6301) {
    echo "Couldn't open shared memory segment\n";
}
$durchschnitt = 0;
$queue = array();
$aktiv = false; // Ist die Steuerung überhautp aktiv (Schütze ein)
$ventil = 0; // Zustand 3-Wege Ventil (0= Wasser von Heizung, 1= Wasser vom Boiler)
$leistung = 0; // Leistung, welche für den Heizstab zur Verfügung steht
$prozent = 0; // Prozentwert der Leistung die Eingestellt ist
$zaehler=0; // Schleifenzähler für div. abgeleitete Funtionen
$temp = 0; // aktuelle Temperatur im Boiler
$battcapfile = "/tmp/iBATTCAP.txt";

//Reduce errors
error_reporting(~E_WARNING);

// Main
while(1)
        {
        $zaehler++;
        // Heizen nur zwischen 07 und 18 Uhr
        $stunde = (date("H"));
        if($stunde>"17" & $stunde<"24" || $stunde>="00" & $stunde<"09")
                {
                if($debug) echo date("Y/m/d H:i:s")." *** Steuerung inaktiv ***\n";
                boiler_aus();
                setboiler(00); // Relais deaktivieren + Temperatur aktualisieren
                //3 Wege Ventil einstellen
                if($ventil == 1 & $temp < "40") {
                        wasser_aus(); // Wenn Wassertemp auf unter 40° gefallen, Ventil auf Heizung umschalten
                        $ventil = 0;
                        logging(" 3-Wege Ventil DE-aktiviert, weil Wasser nur mehr ".$temp."° hat!");
                        if($debug) echo date("Y/m/d H:i:s")." 3-Wege Ventil DE-aktiviert\n";
                }
                sleep(600);
                continue;
                }
        if(!$aktiv)
                {
                boiler_ein(); // Arduino einschalten
                if($debug) echo date("Y/m/d H:i:s")." Heizstab eingeschaltet!\n";
                $aktiv = true;
                }
        // Checken ob Batterie für Hausstrom/USV voll ist, sonst nicht heizen
        $bc = fopen($battcapfile, "r");
        $battperc = fread($bc, 2);
        fclose($bc);
        if($battperc<70){
                if($debug) echo "Batterie nur $battperc %, daher nix heizen!\n";
                setboiler(00);
                sleep(10);
                continue;
                }
        //read shared memory
        $pow =   shmop_read($sh_sdm6301, 18, 6);
        if($debug) echo "**** $pow W aus Shared Memory gelesen\n";
        if($pow < -6000 || $pow > 10000) echo "Problem: die gelesen Leistung ist ".$pow."\n";
        $durchschnitt=$pow;
// LEISTUNGSTUFE EINSTELLEN:
        $leistung = $durchschnitt - ($prozent*26);
        // Mind 520W (20%) müssen geliefert werden, damit Regelung gestartet wird
        if($leistung > -520)
                        {
                        $prozent = 00;
                        setboiler(00);
                        }
        // 20% weil ziwschen 520 und 1040 geliefert werden
        if($leistung < -520 and $leistung > -1040)
                        {
                        if($debug) echo date("Y/m/d H:i:s")." 20% aktiviert\n";
                        $prozent = 20;
                        setboiler(20);
                        }
        if($leistung < -1040 and $leistung > -1560)
                        {
                        if($debug) echo date("Y/m/d H:i:s")." 40% aktiviert\n";
                        $prozent = 40;
                        setboiler(40);
                        }
        if($leistung < -1560 and $leistung > -2080)
                        {
                        if($debug) echo date("Y/m/d H:i:s")." 60% aktiviert\n";
                        $prozent = 60;
                        setboiler(60);
                        }
        if($leistung  < -2080 and $leistung > -2600)
                        {
                        if($debug) echo date("Y/m/d H:i:s")." 80% aktiviert\n";
                        $prozent = 80;
                        setboiler(80);
                        }
        if($leistung < -2600 )
                        {
                        if($debug) echo date("Y/m/d H:i:s")." 100% aktiviert\n";
                        $prozent = 100;
                        setboiler(99);
                        }
        if($zaehler==1)
                {
                if($debug) echo date("Y/m/d H:i:s")." Durchschnitt: ".round($durchschnitt,1).", Prozent: $prozent, Leistung: ".round($leistung,1)."\n";
                $zaehler=0;
                }
        //3 Wege Ventil einstellen
        if($ventil == 0 & $temp > 50) {
                wasser_ein(); // Wenn Wassertemp >50° Ventil auf Boiler umschalten
                if($debug) echo date("Y/m/d H:i:s")." 3-Wege Ventil aktiviert, weil Wasser ".$temp."° hat!\n";
                logging(" 3-Wege Ventil aktiviert, weil Wasser ".$temp."° hat!");
                $ventil = 1;
                }
        if($ventil == 1 & $temp < 40) {
                wasser_aus(); // Wenn Wassertemp auf unter 40° gefallen, Ventil auf Heizung umschalten
                if($debug) echo date("Y/m/d H:i:s")." 3-Wege Ventil DE-aktiviert, weil Wasser ".$temp."° hat!\n";
                logging(" 3-Wege Ventil DE-aktiviert, weil Wasser ".$temp."° hat!");
                $ventil = 0;
                }
        sleep(10); //Wartezeit bis zum nächsten Durchlauf
        }
// ENDE
function boiler_ein() {
        global $aktiv;
        $username = 'admin';
        $password = '*****';
        $post = [
                'saida2on' => 'on',
                ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://192.168.1.166/relay_en.cgi');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        $response = curl_exec($ch);
        curl_close($ch);
        $aktiv = true;
        return;
}
function boiler_aus() {
        global $aktiv;
        $username = 'admin';
        $password = '*****';
        $post = [
                'saida2off' => 'off',
                ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://192.168.1.166/relay_en.cgi');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        $response = curl_exec($ch);
        curl_close($ch);
        $aktiv = false;
        return;
}
function wasser_ein() { // Schaltet 3-Wege Ventil auf E-Boiler um
        $username = 'admin';
        $password = '*****';
        $post = [
                'saida3on' => 'on',
                ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://192.168.1.166/relay_en.cgi');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        $response = curl_exec($ch);
        curl_close($ch);
        return;
}
function wasser_aus() { // Schaltet 3 Wege Ventil wieder auf Erdwärme um
        $username = 'admin';
        $password = '*****';
        $post = [
                'saida3off' => 'off',
                ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://192.168.1.166/relay_en.cgi');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        $response = curl_exec($ch);
        curl_close($ch);
        return;
}
function setboiler($leistung) {
        global $debug, $temp;
        if($leistung > 99) $leistung=99;
        $curl = "http://192.168.1.70/leistung=$leistung";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $curl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $temp_start = strpos($result, "BoilerTemp:");
        $temp_end = strpos($result, "<br");
        $temp_old = $temp;
        $temp = substr($result, $temp_start + 11, $temp_end - ($temp_start +11));
//        echo "TEMP:$temp\r\n";
        if(intval($temp)>5){
                // File wird nur geschrieben, wenn Temp richtig gelesen werden konnte.
                $fd = fopen("/tmp/BoilerTemp.txt","w");
                fprintf($fd,"5(%d*G)\n",$temp);
                fclose($fd);
        }
        else    {
                if($debug) echo date("Y/m/d H:i:s")." Illegale Temperatur gelesen!\n";
                $temp = $temp_old; // Wieder die alte Temperatur rein, weil ofenbar falsh vom Sensor gelesen!
                }
        curl_close($ch);
        return;
}
function wasser($wasser) {
        if($wasser <0 || $wasser>1) $wasser=0;
        $curl = "http://192.168.1.70/wasser=$wasser";
        echo "Aufruf mit ".$curl."\n";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $curl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $wasser_start = strpos($result, "3-Wege-Ventil:");
        $wasser = substr($result, $wasser_start + 14, 1);
        echo "WASSER:$wasser\r\n";
        curl_close($ch);
        return;
}
function logging($txt) {
        syslog(LOG_ALERT,$txt);
}
?>
