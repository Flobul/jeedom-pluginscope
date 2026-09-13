# Données géographiques

PluginScope utilise les données GeoLite2 créées par [MaxMind](https://www.maxmind.com/), via le miroir tiers [P3TERX/GeoLite.mmdb](https://github.com/P3TERX/GeoLite.mmdb). Le workflow du miroir télécharge les bases auprès de MaxMind ; ce miroir n’est pas le service officiel MaxMind.

Les bases Pays et ASN sont téléchargées sur Jeedom. Aucun fichier MMDB n’est inclus dans le dépôt ou l’archive du plugin. Les licences des données sont distinctes de celle du code PluginScope : [conditions GeoLite](https://www.maxmind.com/en/geolite/eula) et [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/).

Le programme vérifie le format, la date de construction et la version locale avant remplacement. Il ne garantit pas la précision des données du miroir. Les adresses analysées ne sont jamais envoyées au fournisseur. Par précaution, les copies GeoLite construites depuis plus de 30 jours sont exclues de la géolocalisation.
