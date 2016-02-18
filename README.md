# suivi-popstan
Exemple de l’utilisation de [CURL](http://php.net/manual/en/book.curl.php) dans une page PHP pour chercher les données du serveur de synchronisation de [CSPro](http://www.census.gov/population/international/software/cspro/) par son [API REST](http://teleyah.com/cspro/syncapi/doc/).

L’idée est celle d’une architecture ou il y’a deux serveurs : le serveur sur lequel est installé l’application de synchronisation de CSPro (cspro-rest-api) et le serveur où se trouve l’application de suivi du recensement (suivi-popstan). Les agents-recenseurs envoi les données de leurs appareils (smartphone, tablette…) au serveur CSPro en passant par la fonction synchronize_data dans CSPro. Ces données sont insérées dans la base de données sur le premier serveur.  L’application de suivi sur le deuxième serveur extrait les données du premier serveur et les mets dans sa base de données locale.
Pour ne pas copier toutes les données chaque fois, le serveur de suivi maintient l’historique des transferts du serveur de CSPro qui lui permet de prendre seulement les cas qui ont changé depuis la dernière mise a jour.


