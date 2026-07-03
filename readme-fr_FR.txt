=== SwiftTrap for Mailtrap ===
Contributors: simmotorlp
Tags: mailtrap, transactional-email, email-api, wp-mail, email-log
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 3.0.1
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Envoyez les e-mails de WordPress via l'API Email de Mailtrap (et non SMTP). Flux groupés et transactionnels, catégories, liste de suppression, journal des e-mails.

== Description ==

**SwiftTrap** est un remplacement direct de `wp_mail()` qui achemine les e-mails de WordPress via l'**API d'envoi Mailtrap** au lieu du SMTP. Il est conçu spécifiquement pour Mailtrap — et non comme une extension SMTP générique dotée d'un préréglage Mailtrap — il expose donc des fonctionnalités propres à Mailtrap que le SMTP ne permet pas : routage vers un flux groupé ou transactionnel, catégories d'e-mails, variables personnalisées pour le suivi, listes de suppression et statut de vérification de domaine.

= Pourquoi une API HTTP plutôt que le SMTP ? =

* **Latence réduite** — un seul appel HTTPS par message, sans allers-retours MAIL FROM / RCPT TO / DATA.
* **Meilleure délivrabilité** — Mailtrap achemine les messages de l'API via ses flux dédiés transactionnels et groupés ; le SMTP n'expose pas cette sélection de flux.
* **Catégories natives** — chaque e-mail est automatiquement catégorisé (bienvenue, réinitialisation du mot de passe, notification, marketing, etc.) afin de pouvoir les filtrer et les analyser dans Mailtrap.
* **Aucun souci de pare-feu** — port 587/465 bloqué ? L'API fonctionne via le HTTPS standard, le port 443.

= Pourquoi SwiftTrap plutôt que WP Mail SMTP / Post SMTP =

* Les extensions SMTP génériques utilisent les identifiants SMTP de Mailtrap et perdent toutes les fonctionnalités propres à Mailtrap.
* SwiftTrap appelle `send.api.mailtrap.io` pour le courrier transactionnel et `bulk.api.mailtrap.io` pour le courrier groupé — automatiquement, selon la catégorie ou via un filtre.
* Aucun SDK PHP Mailtrap requis. L'extension pèse **environ 30 Ko au total** et n'utilise que l'API HTTP de WordPress (`wp_remote_post`).
* La page Statistiques affiche le statut de vérification de votre domaine d'envoi et la liste de suppression en direct (rebonds, réclamations, désabonnements).

= Fonctionnalités =

* Remplacement direct de `wp_mail()` — fonctionne avec WooCommerce, Contact Form 7, Gravity Forms, et toute extension utilisant le système de messagerie de WordPress.
* Catégorisation automatique des e-mails et remplacement du routage de flux via une grille de réglages.
* Suivi de livraison et webhooks — suivi des événements en temps réel via la route REST personnalisée `swifttrap/v1/webhook`.
* Gestion des suppressions — panneau CRUD pour les listes de suppression Mailtrap, avec vérification des destinataires suppressés avant envoi.
* Repli de fiabilité — bascule automatique et silencieuse vers le `wp_mail()` natif de WordPress si l'appel à l'API Mailtrap échoue.
* Intégration à l'État de santé du site — test de vérification du statut du jeton Mailtrap et de la vérification du domaine d'envoi.
* Journal des e-mails en direct — parcourez et filtrez les données de livraison récupérées directement depuis l'API Mailtrap ; recherchez par adresse du destinataire, statut ou plage de dates, avec pagination automatique.
* Commandes WP-CLI — gestion en ligne de commande via `wp swifttrap` (test, stats, prune-logs, send-suppression-sync).
* Garde-fou sur la taille des pièces jointes — limites configurables pour éviter que des fichiers trop volumineux ne soient rejetés par la passerelle de l'API.
* Bouton d'e-mail de test sur la page des réglages.
* Prise en charge des modèles Mailtrap via `template_uuid`.
* Bascule vers le gestionnaire de messagerie WordPress par défaut lorsque l'extension est désactivée ou que le jeton est vide.

= Extensible via des filtres =

* `swifttrap_mailtrap_email_category` — remplacer la catégorie d'e-mail détectée automatiquement.
* `swifttrap_mailtrap_use_bulk_stream` — forcer un message vers le flux groupé ou transactionnel.
* `swifttrap_mailtrap_template` — envoyer via un modèle Mailtrap identifié par `template_uuid`.
* `swifttrap_mailtrap_custom_variables` — joindre des métadonnées de suivi aux e-mails sortants.

= Confidentialité =

Cette extension envoie le contenu des e-mails (destinataires, objet, corps, pièces jointes) à l'API Mailtrap, sur `send.api.mailtrap.io` et `bulk.api.mailtrap.io`. Les statistiques de compte et les journaux d'e-mails sont récupérés depuis `mailtrap.io/api/accounts` et `mailtrap.io/api/email_logs`. Consultez la [Politique de confidentialité de Mailtrap](https://mailtrap.io/privacy-policy). Aucune donnée n'est envoyée ailleurs.

== Installation ==

1. Installez depuis **Extensions → Ajouter** en recherchant *SwiftTrap for Mailtrap*, ou téléversez le dossier `swifttrap-for-mailtrap` dans `/wp-content/plugins/`.
2. Activez l'extension.
3. Allez dans **Mailtrap → Réglages**.
4. Collez votre **jeton API d'envoi** Mailtrap (tableau de bord Mailtrap → Domaines d'envoi → Jetons API).
5. Définissez votre e-mail et nom d'expéditeur vérifiés.
6. Cliquez sur **Envoyer un e-mail de test** pour vérifier la livraison.

== Frequently Asked Questions ==

= Pourquoi utiliser SwiftTrap plutôt que WP Mail SMTP ou Post SMTP avec les identifiants Mailtrap ? =

WP Mail SMTP et Post SMTP passent par la passerelle SMTP de Mailtrap et traitent Mailtrap comme un simple hôte SMTP parmi d'autres. SwiftTrap utilise l'API d'envoi HTTP de Mailtrap, qui expose des fonctionnalités que le SMTP ne permet pas : routage vers un flux groupé ou transactionnel, catégories, variables de suivi personnalisées, UUID de modèles, et visibilité en direct sur la liste de suppression. Utilisez SwiftTrap si vous souhaitez un comportement propre à Mailtrap ; utilisez une extension SMTP générique si vous préférez une configuration unique compatible avec tous les fournisseurs.

= Prend-elle en charge les modèles d'e-mail Mailtrap ? =

Oui — utilisez le filtre `swifttrap_mailtrap_template` pour envoyer via un `template_uuid`. Les variables du modèle peuvent être transmises via la charge utile standard des variables de modèle de Mailtrap.

= Comment fonctionne le routage vers le flux groupé ? =

Par défaut, les catégories marketing/promotionnelles sont routées vers `bulk.api.mailtrap.io` et tout le reste vers `send.api.mailtrap.io`. Remplacez ce comportement par message avec le filtre `swifttrap_mailtrap_use_bulk_stream` — utile pour les newsletters envoyées en lot depuis une extension personnalisée.

= Où puis-je obtenir mon jeton API ? =

Connectez-vous à [mailtrap.io](https://mailtrap.io), ouvrez votre domaine d'envoi, allez dans **Jetons API**, et créez un jeton avec les permissions d'envoi.

= Que se passe-t-il si je désactive l'extension ou supprime le jeton ? =

WordPress revient à son gestionnaire `wp_mail()` par défaut. Aucun e-mail n'est silencieusement perdu.

= L'extension nécessite-t-elle le SDK PHP Mailtrap ? =

Non. SwiftTrap appelle directement l'API REST de Mailtrap via l'API HTTP de WordPress. La taille totale de l'extension est d'environ 30 Ko.

= Quelles données sont envoyées à l'extérieur ? =

Les données des e-mails (destinataires, objet, corps, pièces jointes) sont envoyées vers `send.api.mailtrap.io` et `bulk.api.mailtrap.io`. Les statistiques du compte sont récupérées depuis `mailtrap.io/api/accounts`. Consultez la [Politique de confidentialité de Mailtrap](https://mailtrap.io/privacy-policy).

= Y a-t-il une limite de taille pour les pièces jointes ? =

Oui — 25 Mo par e-mail (conforme à la limite de l'API Mailtrap).

== Screenshots ==

1. Page des réglages — jeton API, expéditeur vérifié, routage des flux.
2. Page des statistiques — statut de vérification du domaine d'envoi et liste de suppression (rebonds, réclamations, désabonnements).
3. Journal des e-mails — données en direct de l'API Mailtrap avec filtres et pagination.
4. Widget du tableau de bord affichant le statut de l'intégration, l'expéditeur et des liens rapides vers les statistiques et les réglages.
5. Confirmation d'e-mail de test.

== Changelog ==

= 3.0.1 =
* Correction : le récepteur de webhook vérifie désormais le véritable en-tête `Mailtrap-Signature` HMAC-SHA256 de Mailtrap au lieu d'un en-tête que Mailtrap n'envoie jamais. Chaque véritable appel de webhook de suivi de livraison était systématiquement rejeté depuis l'introduction de cette fonctionnalité en 2.4.0.
* Correction : l'analyse de la charge utile du webhook déballe désormais correctement l'enveloppe `{"events": [...]}` de Mailtrap, afin que les événements vérifiés atteignent `do_action('swifttrap_mailtrap_webhook_event', ...)`.
* Correction : la carte d'utilisation de la page Statistiques appelle désormais le point de terminaison actuel `/api/billing/usage` de Mailtrap, au lieu d'un ancien chemin propre au compte qui ne renvoyait aucune donnée.
* Correction : la désinstallation de l'extension efface désormais les véritables transients en cache, au lieu de noms de clés antérieurs à 2.3.0 qui ne correspondent plus.
* Amélioration : la recherche de destinataires dans le journal des e-mails et les appels à l'API de compte utilisent désormais systématiquement la syntaxe de filtre entre crochets et l'authentification par jeton Bearer.

= 3.0.0 =
* Changement majeur : suppression de toute la journalisation locale des e-mails basée sur des fichiers. Plus aucun fichier journal écrit sur le disque — élimine le risque de saturation mémoire/disque sur les sites à fort volume.
* Nouveau : le panneau Journal des e-mails de la page Statistiques récupère désormais les données en direct directement depuis l'API Mailtrap (`GET /api/email_logs`).
* Nouveau : le journal des e-mails prend en charge le filtrage par adresse e-mail du destinataire, statut de livraison et plage de dates.
* Nouveau : pagination côté client — met en mémoire tampon jusqu'à 1 000 entrées de Mailtrap par appel API, affiche 20 lignes à la fois avec une navigation Précédent/Suivant. Récupère automatiquement le lot suivant lorsque le tampon est épuisé.
* Nouveau : le gestionnaire de webhook déclenche désormais `do_action('swifttrap_mailtrap_webhook_event', $event)` pour chaque événement de livraison, permettant des intégrations tierces sans modifier l'extension.
* Supprimé : export CSV, effacement des fichiers journaux, fenêtre modale de détail des journaux, renvoi des journaux, réglage du nombre de journaux par page, et nettoyage des journaux par cron. Tout est désormais remplacé par la vue API en direct.
* Correction : la page Statistiques ne crée plus un attribut nonce redondant sur l'élément conteneur.

= 2.4.2 =
* Correction : le journal des e-mails perdait la plupart des entrées lors d'envois à fort volume ou concurrents. Chaque écriture relisait et réécrivait tout le fichier journal, si bien que des processus parallèles écrasaient mutuellement leurs lignes. Les écritures utilisent désormais un ajout atomique avec verrouillage exclusif, si bien que le tableau de bord Statistiques (envois par jour, catégories, totaux) reflète le nombre réel d'e-mails envoyés.
* Amélioration : la journalisation ne ralentit plus les envois importants — les ajouts sont désormais en O(1) au lieu de relire et réécrire tout le fichier à chaque e-mail.

= 2.4.1 =
* Correction : la liste de suppression lit désormais le champ `type` de Mailtrap, si bien que le tableau de bord affiche les véritables décomptes BOUNCE / COMPLAINT / UNSUBSCRIBE / MANUAL au lieu de marquer chaque entrée comme manuelle.
* Nouveau : les lignes de suppression affichent la catégorie de rebond du message (lorsqu'elle est fournie) pour le détail des rebonds durs.
* Correction : les dates de suppression sont désormais formatées côté serveur selon le format de date du site, au lieu de la locale du navigateur.
* Nouveau : lien « Tout voir dans Mailtrap » sur la carte des suppressions.
* Nouveau : sélecteur du nombre d'éléments par page (10/25/50/100) sur l'écran Journal des e-mails.
* Amélioration : les actions d'en-tête du journal des e-mails sont désormais alignées à droite ; le champ de filtre de date est restylé pour correspondre aux autres champs.

= 2.4.0 =
* Nouveau : point de terminaison REST de webhook (`swifttrap/v1/webhook`) pour suivre les statuts distribué, rejeté, ouvert et cliqué.
* Nouveau : gestion CRUD des suppressions dans les statistiques d'administration et vérifications des destinataires avant envoi pour ignorer les e-mails suppressés.
* Nouveau : mécanisme de repli renvoyant `null` dans `pre_wp_mail` en cas d'échec de l'API, afin que `wp_mail` natif envoie l'e-mail à la place.
* Nouveau : test de connexion et de statut de vérification de domaine dans l'État de santé du site.
* Nouveau : interface des journaux d'administration améliorée avec recherche, filtrage, export CSV, fenêtres modales d'aperçu de charge utile en iframe et actions de renvoi.
* Nouveau : grille de réglages de catégories pour les règles de correspondance flux/catégorie et les remplacements d'expéditeur.
* Nouveau : espace de noms WP-CLI `wp swifttrap` (test, stats, prune-logs, send-suppression-sync).
* Nouveau : réglage de garde-fou pour la taille des pièces jointes.
* Refactorisation : extraction du formateur de lignes CSV dans une fonction utilitaire pour les tests unitaires. Entièrement couverte et vérifiée par la suite de tests.

= 2.3.0 =
* PHP 8.0 est désormais le minimum requis ; testé jusqu'à WordPress 7.0.
* Fiabilité : nouvelle tentative automatique avec délai progressif en cas d'erreurs transitoires de l'API Mailtrap (429/5xx, respecte Retry-After).
* Rétention des journaux déterministe via un événement cron quotidien (remplace le nettoyage probabiliste précédent).
* Les caches de compte/statistiques/domaine/suppressions sont désormais indexés par jeton API, si bien que changer de jeton ne sert plus de données obsolètes.
* Gestion JSON robuste pour toutes les réponses de l'API Mailtrap ; cache des réglages compatible multisite.
* Nouveau : bouton « Vérifier le jeton » sur l'écran des réglages.
* Code modernisé selon les idiomes PHP 8 ; première suite de tests unitaires ajoutée.

= 2.2.2 =
* Plugin URI : pointe désormais vers la page dédiée sur https://plugins.symonov.com/swifttrap-for-mailtrap/
* Aucun changement de code ni de comportement

= 2.2.1 =
* Readme : réécriture axée USP mettant en avant l'API Email Mailtrap (par rapport au SMTP) et le routage vers les flux groupés/transactionnels
* Tags : remplacement de `email`/`mail`/`smtp` par les tags ciblés `mailtrap`, `transactional-email`, `email-api`, `wp-mail`, `email-log`
* FAQ : ajout d'une comparaison avec WP Mail SMTP / Post SMTP, de la prise en charge des modèles Mailtrap, et du routage vers le flux groupé
* Testé jusqu'à WordPress 7.0

= 2.2.0 =
* Remplacement de tous les file_get_contents/file_put_contents par l'API WP_Filesystem
* Correction de l'assainissement de $_GET avec un wp_unslash() approprié et des annotations phpcs
* Amélioration des en-têtes PHPDoc dans tous les fichiers
* Meilleure conformité aux normes de codage WordPress

= 2.1.0 =
* Ajout du statut de vérification du domaine d'envoi sur la page Statistiques
* Ajout de la liste de suppression (rebonds, réclamations, désabonnements) sur la page Statistiques
* Ajout du filtre `swifttrap_mailtrap_template` pour la prise en charge des modèles Mailtrap
* Ajout du filtre `swifttrap_mailtrap_custom_variables` pour les métadonnées de suivi des e-mails
* Extraction de `swifttrap_mailtrap_get_account_id()` réutilisable avec mise en cache par transient

= 2.0.0 =
* Suppression de la dépendance au SDK Mailtrap — utilise directement l'API HTTP de WordPress
* Zéro dépendance externe, environ 30 Ko au total pour l'extension
* Amélioration de la conformité WP.org

= 1.3.0 =
* Sécurité : protection du répertoire des journaux contre l'accès web direct
* Ajout de la validation de la taille des pièces jointes (limite de 25 Mo)
* Ajout de la validation des destinataires vides
* Correction de la gestion du fuseau horaire dans l'affichage des journaux
* Optimisation du calcul de la catégorie d'e-mail
* Amélioration du verrouillage des fichiers journaux

== Upgrade Notice ==

= 3.0.1 =
Correction importante : les événements de suivi de livraison des webhooks Mailtrap étaient rejetés en raison d'une incompatibilité dans la vérification de signature, et n'ont jamais été traités depuis la version 2.4.0. Mettez à jour si vous utilisez l'intégration webhook.

= 2.4.0 =
Met à niveau l'extension WordPress vers la 2.4.0, introduisant les webhooks de suivi de livraison, la gestion des suppressions, un repli natif en douceur, une interface de journaux améliorée avec export CSV, des commandes WP-CLI, et un test de l'État de santé de WordPress.

= 2.3.0 =
Version de fiabilité mineure : nouvelles tentatives d'envoi automatiques en cas d'erreurs API transitoires, nettoyage des journaux par cron, et mises à jour modernes vers PHP 8.

= 2.2.2 =
Le Plugin URI pointe désormais vers la page dédiée sur plugins.symonov.com. Aucun changement de code.

= 2.2.1 =
Version documentation uniquement. Readme actualisé et compatibilité confirmée avec WordPress 7.0.

= 2.2.0 =
Passage aux normes de codage WordPress — API WP_Filesystem, assainissement des entrées renforcé, et PHPDoc amélioré. Aucun changement de configuration requis.
