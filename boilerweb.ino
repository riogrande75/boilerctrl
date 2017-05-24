
/* SSR Steuerung zur Leistungsbegrenzung eines Elektroheizstabes
   Zusätzlich Ausgabe der Boilertemperatur
   Schaltung des
*/

#include <SPI.h>
#include <Ethernet.h>
#include <OneWire.h>
#include <DallasTemperature.h>

 // DS18S20 Temperaturchip i/o
#define ONE_WIRE_BUS 2
OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);

//Defs für "Heizung nur alle 1 Minten abfragen
const unsigned long INTERVAL = 1000L*60; // letzer Wert sind Sekunden
unsigned long lastRun = 0 - INTERVAL;    // damit es gleich beim Start losgeht


int wasserpin = 6;    // PIN für 3-Wege-Ventil Steuerung
int heizPin = 7;      // PIN für SSR Steuerung
int prozent = 0;      // aktuelle Leistung des Boilers
int leistung;         // Leistung von IP übergeben
int temperatur = 45;  // Temperatur vom Boiler

byte mac[] = { 0x54, 0x55, 0x58, 0x11, 0x00, 0x34 }; // entspricht einer MAC von 84.85.88.16.0.36
byte ip[]  = { 192, 168, 1, 70 };                    // IP-Adresse
byte gateway[] = { 192, 168, 1, 254 };               // Gateway
byte subnet[]  = { 255, 255, 255, 0 };

int wasser = 0; //3WegeVerntil: 0 = Wärmepumpe, 1 = Boiler

EthernetServer server(80);

String readString = String(100);      // string for fetching data from address

void setup(){
  Ethernet.begin(mac, ip, gateway, subnet);
  server.begin();
  pinMode(heizPin, OUTPUT);
  sensors.begin();
  Serial.begin(9600);
}

void loop(){
// MAIN: ETHERNET ABFRAGE + ANTWORT
EthernetClient client = server.available();
if (client) { // Wenn Meldung über IP kommt dieser Zweig
  while (client.connected()) {
    if (client.available()) {
      char c = client.read();

      //read char by char HTTP request
      if (readString.length() < 100) {
        //store characters to string
        // readString.append(c);  removed by Katsu
        readString = readString + c; // insert by Katsu
        // very simple but it works...
      }
      Serial.print(c);  //output chars to serial port

      if (c == '\n') {  //if HTTP request has ended

      if(readString.indexOf("leistung=") > -1) {
        prozent=readString.substring(14,16).toInt();
        if (prozent==99) prozent=100;
        Serial.print("Leistung: ");
        Serial.print(prozent);
        Serial.println(" %");
      }
       if(readString.indexOf("wasser=") > -1) {
        wasser=readString.substring(12,13).toInt();
        Serial.print("Wasser: ");
        Serial.print(wasser);
      }
     
      //--------------------------HTML------------------------
      client.println("HTTP/1.1 200 OK");
      client.println("Content-Type: text/html");
      client.println();
      client.print("<html><head>");
      client.print("<title>Rios Arduino BoilerControl Webserver</title>");
      client.println("</head>");
      client.print("BoilerTemp:");
      client.print(temperatur); // HIER AUSGABE der BoilerTEMP
      client.println("<br />");
      client.print("Heizleistung:");
      client.print(prozent); // HIER AUSGABE der aktuellen Heizleistung
      client.println("<br />");
      client.print("3-Wege-Ventil:");
      client.print(wasser); // HIER AUSGABE der Stellung des 3WegeVentils
      client.println("<br />");
      client.println("<br />");
      client.print("<a href=\'http://192.168.1.70/leistung=99'>VOLLE LEISTUNG</a><br />");
      client.print("<a href=\'http://192.168.1.70/leistung=50'>HALBE LEISTUNG</a><br />");
      client.print("<a href=\'http://192.168.1.70/leistung=00'>NULL LEISTUNG</a><br />");
      
//      client.print("  Für 100% hier klicken: http://192.168.1.70/leistung=99");
      client.println("<br />");
      client.println("</body></html>");

        //clearing string for next read
        readString="";

        //stopping client
        client.stop();
        }
      }
    }
  }
// REST von MAIN
  
  // Temperatursensor lesen (alle 1 Minuten)
     if ( millis() - lastRun >= INTERVAL )
      {
      sensors.requestTemperatures();
      Serial.print("Gemessene BoilerTemperatur: ");
      Serial.println(sensors.getTempCByIndex(0)); // Why "byIndex"? 
      temperatur=(sensors.getTempCByIndex(0));
      // You can have more than one IC on the same bus. 
      // 0 refers to the first IC on the wire
      lastRun += INTERVAL;
    }
  //WasserPIN setzen
   if (wasser==1) // Vom Server kam wasser=1
      {
      digitalWrite(wasserpin, HIGH);
      } 
    else  // wasser=0
    {
      digitalWrite(wasserpin, LOW);
    }     
  // Leistung ausgeben auf SSR
  // 1 Vollwelle = 20ms
  // 1% = 20ms ; 100% = 2000ms
  // 50*20ms=1000ms ; 50 Vollwellen durchlassen
    static unsigned long letztesIntervallStart;
    unsigned long jetztMillis=millis();
    if (jetztMillis-letztesIntervallStart>2000){ // alle 2000ms ein neues Intervall
      letztesIntervallStart=jetztMillis;
    }
    if (jetztMillis-letztesIntervallStart<20*prozent) // bestimmte Anzahl an Vollwellen durchlassen
      {
      digitalWrite(heizPin, HIGH);
      } 
    else  // Rest des Intervalls Vollwellen nicht durchlassen
    {
      digitalWrite(heizPin, LOW);
    }   
}
