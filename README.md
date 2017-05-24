# boilerctrl
A little php script reading the power production data of my solar power plants (deducted consumption of my house).
Instead of delivering this power to grid, getting not a lot from my energy supplier I'd rather power my electric water heater (boiler).
Therefor this script sends commands (via http) to an arduino (with ethernet module:ip 192.168.1.70). The arduino controls a solid state relay (SSR40DA) to limit the power of the heater.
The adruino has a DS18S20 connected, so it can read the actual temperature of the boiler and sends it back to the raspberry.

Any questions, pls. post to www.photovoltaikforum.com/infinisolar-3k-10k-logging-und-feedin-control-t115416.html.
