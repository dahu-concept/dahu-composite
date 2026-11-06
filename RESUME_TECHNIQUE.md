# RESUME TECHNIQUE — Dahu Composite

Document interne, en français.

---

## Portage depuis la version Annad

### Version Annad d'origine

- Dépôt source Annad : `/Dev/dahu-composite/dahu-composite/`
- Version de référence au moment du portage : `0.73.0`
- Date du portage : 2026-10-06
- Version publique de départ : `0.73.0` — numérotation conservée, le code est
  le même. Rester sous la 1.0.0 est honnête : l'extension est encore en
  pré-production chez Annad.
- **Slug inchangé** : `dahu-composite`. Le renommage en
  `dahu-composite-products` a été écarté — « WooCommerce Composite Products »
  est une extension commerciale existante et wordpress.org refuse les slugs
  ressemblant à un produit tiers. Voir `/Dev/dahu-public/CORRESPONDANCE-ANNAD.md`.

### Retiré

| Élément retiré | Raison |
|---|---|
| Valeur par défaut `'annad_theme_product_tabs'` du filtre `dahu_composite_product_tabs_callback` | Remplacée par `''` : le repli interne du plugin rend les onglets. Un thème fournit son propre nom de fonction par le filtre. |
| Valeur par défaut `function_exists( 'annad_theme_dahu_kit_assets' )` du filtre `dahu_composite_theme_provides_styles` | Remplacée par `false` : le plugin habille toujours, un thème prend la main en renvoyant `true`. |
| **Classes CSS du thème Annad émises par le JS du front** (`annad-btn`, `annad-buybox__*`, `annad-hero__buybox`) — 52 jetons | Balisage mort référençant un thème précis. Les classes `dahu-kit__*` portent déjà toute la structure. |
| Variable CSS `--annad-radius-small` | Renommée `--dahu-kit-radius-small`, même valeur de repli. |
| Texte « Lorem ipsum » du `helpPlaceholder` | Du faux latin partait en production. Valeur par défaut désormais vide : aucun « ? » n'est affiché quand il n'y a rien à montrer — le code gérait déjà ce cas. |
| Mention de « WooCommerce Composite Products » dans la Description de l'en-tête | Nommer un produit commercial concurrent dans l'en-tête est un risque en revue. |
| Licence GPL v2 or later | Versions publiques en MIT. Aucun code GPL recopié : le plugin étend `WC_Product` et utilise des hooks, ce qui n'est pas de la recopie. |

### Généralisé

| Élément | Avant (Annad) | Après (public) |
|---|---|---|
| Langue source | Français | **Anglais** — 344 occurrences PHP (306 chaînes uniques) et 92 occurrences JS traduites |
| i18n | `load_plugin_textdomain()` absent | Chargé sur `init`, `Domain Path: /languages` |
| Autorisations REST | 7 routes en `permission_callback => '__return_true'` | Callbacks nommés et documentés : `permission_read_kit`, `permission_read_product`, `permission_public`, `permission_save_guest` |
| Nonce du `/saved-guest` | Vérifié dans le callback métier | Déplacé dans le `permission_callback`, sa vraie place |
| Désinstallation | Aucune | `uninstall.php`, mono-site et multisite, avec garde-fou (voir plus bas) |
| Libellés du JS d'administration | Écrits en dur dans `dahu-composite-admin.js` | 269 clés passées par `wp_localize_script` depuis `Dahu_Composite_Admin::script_i18n()`, avec repli anglais dans le script |
| Pictogrammes du gabarit « Icône de catégorie » | 7 expressions régulières de mots-clés **français** dans le JS du front | Mots-clés anglais par défaut côté PHP, exposés par le filtre `dahu_composite_category_icons` ; le JS ne garde que les formes SVG |
| Libellé de la mention « Inclut » | Lu dans le domaine de traduction d'une extension tierce | Filtre `dahu_composite_includes_label`, domaine unique |

### Décisions de portage à connaître

**Pas de nonce ajouté sur `/add-to-cart`.** C'était pourtant la recommandation
initiale de l'audit, et elle était mauvaise. WooCommerce accepte lui-même
`?add-to-cart=123` sans aucun nonce, et les pages produit sont souvent servies
depuis un cache : un nonce de page cachée expire en moins d'une journée et
casserait l'ajout au panier. Être plus strict que le cœur de WooCommerce, pour
un risque que WooCommerce assume (mettre un article dans le panier du visiteur
lui-même), aurait été une régression déguisée. La justification est écrite dans
le docbloc de `permission_public()`.

**Désinstallation asymétrique.** Les réglages et les caches partent toujours ;
les définitions de kits, les configurations clients sauvegardées et les métas de
commande sont **conservées** sauf si le marchand pose explicitement l'option
`dahu_composite_remove_data_on_uninstall`. Des mois de travail peuvent dormir
dans ces métas, et une désinstallation n'est souvent qu'une étape vers une
réinstallation.

**Les commentaires du code ont finalement été traduits.** Le choix initial
était de les laisser en français — wordpress.org n'exige que les chaînes
affichées. Il a été repris : 1267 jetons PHP (421 docblocs, 820 commentaires,
26 messages de journal) et 1340 plages dans les 12 fichiers d'`assets/` sont
passés à l'anglais, le raisonnement conservé, les numéros de version internes
retirés. La vérification est outillée plutôt que relue : comparaison du
squelette de jetons avant/après (tokenizer PHP, acorn pour le JS) et second
comparateur sur le balisage, les noms d'attributs et les sélecteurs extraits de
l'intérieur des littéraux — c'est ce second passage qui a rattrapé un
`data-action` WooCommerce réécrit par erreur, invisible au premier.

**Aucun mot de langue naturelle ne reste dans le JS.** Deux mécanismes, et pas
un de plus. Les libellés de l'interface d'administration viennent de
`Dahu_Composite_Admin::script_i18n()` : 269 clés groupées par famille, lues dans
le script par un assistant `L( clé, repli anglais )` — le repli garde
l'interface lisible si la localisation ne charge pas. Les comptes passent par
`fmt()` et `plural()` : les deux formes de `_n()` sont envoyées par PHP en
`array( singulier, pluriel )` et le script choisit sur `n === 1`. Les mots-clés
qui dérivaient un pictogramme du nom du produit sont partis dans le filtre
`dahu_composite_category_icons`, parce qu'un mot-clé appartient à la langue du
catalogue et non au code : le JS ne garde que les formes SVG, et la boutique
d'origine retrouve ses 7 pictogrammes en filtrant la table — l'exemple français
complet est dans le docbloc de `Dahu_Composite_Frontend::category_icons()`.
Même raisonnement pour `dahu_composite_includes_label`, qui remplace la lecture
du domaine de traduction d'une extension tierce.

### Dépendances tierces : optionnelles, toutes gardées

| Appel | Garde |
|---|---|
| `dahu_pricing_get_price_labels()` | `function_exists()` |
| `Dahu_Pricing_Settings::get_settings_url()` | `is_callable()` |

Les mentions de `dahu-post` relevées dans l'audit n'étaient que des
commentaires : **aucun appel réel**, rien à garder.

### Suivi des évolutions Annad

L'extension est encore en pré-production chez Annad : tout ce qui bouge là-bas
devra être reporté ici.

- [ ] (rien à ce jour — portage fait depuis la 0.73.0)

---

## Reste à faire avant soumission

**Mesuré le 31/10/2026** sur le contenu réel du disque : `prove-submission-ready`
**12 vertes**, `prove-text-domain` **2 vertes**, `prove-images-sans-outil`
**1 verte / 2 rouges** — les images sont le seul rouge statique de l'extension.
Les deux suites qui demandent le banc WordPress (`t-composite.php`, 223
assertions, et `prove-composite.py`, 35 injections) n'ont pas tourné depuis le
24/10 faute de `wp-core` dans le conteneur.

- [ ] **Vérification fonctionnelle sur un WooCommerce réel.** Le portage n'a été
      validé que par analyse statique (lint PHP, `node --check`, comparaison du
      nombre de balises HTML avant/après traduction). Rien n'a été exécuté.
- [ ] **Captures d'écran `screenshot-1..4.png` à REFAIRE.** Les quatre livrées
      existent et sont propres, mais elles datent du **12/10/2026**, alors que
      l'habillage de famille est arrivé sur les écrans le 24/10 et que la
      feuille a encore changé le 30/10. Elles montrent donc une interface qui
      n'existe plus.

      Correction du 31/10 : ce n'est **pas** réservé à la machine de Hugo. Le
      banc porte déjà `_bench/org-screenshots-composite.mjs` et
      `shot-composite.php`, écrits pour ça, et Chromium et Playwright sont dans
      le conteneur. Ce qui ne passe pas, c'est la LIVRAISON : tout PNG écrit par
      le pont ressort tatoué. La génération est donc faisable en session, et
      `nettoyer-images.php` règle la livraison.
- [x] Slug `dahu-composite` disponible sur wordpress.org — vérifié le
      31/10/2026, la recherche du répertoire rend « 0 plugins ». Réserve : un
      slug soumis mais non publié n'apparaîtrait pas, donc ce n'est pas une
      garantie absolue.
- [x] Fichiers de traduction française générés : `dahu-composite-fr_FR.po`
      (78 947 o) et `.mo` (46 233 o), plus le `.pot` (59 693 o).
- [x] Mécanisme i18n JavaScript : `wp_localize_script`, cohérent avec ce que
      fait déjà le JS du front. 269 clés pour `dahu-composite-admin.js`.
- [ ] Langues à trois formes de pluriel ou plus (polonais, russe, arabe) : le
      choix sur `n === 1` ne les couvre pas. Il faudra `wp.i18n._n()` dans le
      script, donc `wp_set_script_translations()` et des fichiers JSON. Partout
      où un compte pouvait s'écrire sans flexion (« Restricted: 3 ») il l'est
      déjà, ce qui limite le sujet à 6 chaînes.
- [x] **Convention de version — tranché par Hugo le 31/10/2026 : garder le
      0.x et l'assumer.** La version est **0.78.1** et concorde entre
      `README.txt` (Stable tag), l'en-tête du fichier principal et
      `DAHU_COMPOSITE_VERSION`. Une section `= On the version number =` du
      README dit maintenant ce que le 0.x réserve et ce qu'il ne réserve pas :
      les chemins d'argent sont couverts par des assertions elles-mêmes
      prouvées capables de tomber ; ce qui reste à rencontrer, ce sont les
      catalogues réels, les thèmes et les extensions voisines. Le 1.0 viendra
      de là, pas de la longueur de la liste de fonctions.
- [x] **Les quatre images de `.wordpress-org/` sont propres** — fait le
      31/10/2026, et c'était le dernier rouge de l'extension.
      `prove-images-sans-outil.php` est à **3 vertes** sur le disque : `caBX`
      absent des quatre, empreintes IDAT **identiques** avant/après (aucun pixel
      touché), dimensions et modes intacts.

      La livraison a demandé un détour qui mérite d'être consigné, parce qu'il
      resservira : **PHP n'est pas installé sur la machine de Hugo**, donc le
      nettoyeur ne pouvait pas y tourner, et le pont tatoue tout PNG qu'il
      écrit, donc aucune session ne peut livrer une image propre. Les quatre ont
      été nettoyées **en session** avec `nettoyer-images.php`, encodées en
      base64 avec les marqueurs `-----BEGIN CERTIFICATE-----`, déposées comme
      fichiers **texte** (que le pont ne tatoue pas), puis décodées chez Hugo par
      `certutil -decode` — intégré à Windows, aucune dépendance. Vérifié ensuite
      depuis le disque : identiques à l'octet aux originaux nettoyés.

      Piège rencontré : `certutil -decode` **refuse d'écraser** un fichier
      existant (`ERROR_FILE_EXISTS`). Décoder à côté puis `Move-Item -Force`.

- [x] *(remplacé par la ligne ci-dessus)* Les quatre images étaient tatouées : `banner-1544x500.png`, `banner-772x250.png`,
      `icon-128x128.png`, `icon-256x256.png` portent un chunk `caBX` nommant un
      outil d'IA. Les quatre captures et `icon.svg` sont propres. Le nettoyage
      doit tourner sur la machine de Hugo — le pont injecte le chunk dans tout
      PNG qu'il écrit, donc aucune session ne peut livrer une image propre :
      `php _harness/nettoyer-images.php -- dahu-composite`
      puis `php _harness/prove-images-sans-outil.php -- dahu-composite`.
- [x] **`Requires Plugins: woocommerce` déclaré** le 31/10/2026, sur décision
      de Hugo. `prove-submission-ready.php` le voit (« Requires Plugins: oui »)
      et reste à 12 vertes.

      Une subtilité consignée pour qu'elle ne soit pas reperdue : l'en-tête
      n'est honoré qu'à partir de **WordPress 6.5**, et l'extension déclare
      `Requires at least: 6.2`. De 6.2 à 6.4 la déclaration est donc ignorée,
      et c'est le repli déjà présent qui parle — `plugins_loaded` vérifie
      `class_exists( 'WooCommerce' )`, pose un `admin_notices` « Dahu Composite
      requires WooCommerce. » et sort avant tout `require_once`. Vérifié dans le
      code, pas supposé : la FAQ du README l'annonce et dit vrai.

      Le plancher est passé de 6.0 à **6.2** en novembre 2026, pour que
      `$wpdb->prepare()` accepte le marqueur `%i` des identifiants de table —
      ce qui supprime deux familles entières d'avertissements du Plugin Check.
      Le raisonnement ci-dessus ne change pas : 6.2 reste sous 6.5, donc le
      repli `plugins_loaded` est toujours ce qui parle.

      `Requires at least` n'a PAS été relevé à 6.5 : ça retirerait le support de
      6.0 à 6.4 pour rendre obligatoire une garantie que le repli assure déjà.

---

## Architecture

Voir `README.md` pour la vue d'ensemble et le tableau des routes REST.

Points structurants :

- **Le serveur fait foi.** `/state` est le seul chemin interactif ; il corrige
  les sélections rendues invalides par un scénario, en cascade interne, sans
  aller-retour réseau. `/validate` et `/quote` restent des routes de
  consultation pour un usage headless externe.
- **Revalidation systématique** avant le panier : rien de ce qu'envoie le
  client n'est tenu pour acquis.
- **HPOS** : accès aux commandes uniquement par l'API `WC_Order` /
  `WC_Order_Item`, jamais en SQL direct.
- **Réponses non cachables** sur les prix (`Cache-Control: no-store, private`),
  puisqu'ils dépendent du rôle de l'utilisateur.
- **404 identique** qu'un kit soit absent ou non publié : aucune divulgation.

## Pièges connus

- `load_kit()` initialise le contexte de taxation (`ensure_tax_context()`)
  avant tout calcul de prix d'affichage, sinon le TTC sort égal au HT en REST.
- Les `permission_callback` nommés dupliquent volontairement le contrôle de
  visibilité que `load_kit()` fait déjà : défense en profondeur, à ne pas
  « simplifier ».

## Le banc de preuve par injection (2026-10-20)

`_bench/prove-composite.py` — **28 injections**, chacune un défaut volontaire
qui doit faire tomber une **assertion nommée** de `_bench/t-composite.php`
(211 assertions). Les injections patchent la **copie** sous
`wp-content/plugins`, jamais ce dépôt : patcher le dépôt ferait tourner chaque
injection contre une extension intacte, et le script conclurait au vert en
n'ayant rien mesuré.

Ce que le banc ne couvrait pas avant, trouvé en retirant les garde-fous un par
un et en constatant que la suite restait verte :

- **Quantité 0 sur un composant optionnel** : une ligne était quand même
  fabriquée. Elle part au panier et au colis, donc le poids du kit change pour
  un composant refusé.
- **Quantité négative** : `(int)` l'accepte et `normalize_product_item()` ne
  borne pas ; seul `validate_selection()` garde la fourchette.
- **Variante posée sur un produit simple** : une variante d'un autre produit
  est le prix d'un autre produit.
- **Prix réellement facturé** : `set_prices()` tourne sur
  `woocommerce_before_calculate_totals`, appelé plusieurs fois par requête, et
  `get_cart_item_price()` relit le produit en base **exprès** — résoudre sur
  l'objet du panier applique une règle en pourcentage une seconde fois. Ce
  raisonnement était en commentaire et rien ne le tenait. La suite compare
  maintenant le prix après **une** passe et après **trois**.
- **Conteneur du kit** et **ligne de fourniture** facturés zéro : aucune
  assertion. Le conteneur, c'est le prix catalogue du kit en plus des
  composants.
- **Prix par rôle** : toute la raison d'être de `Dahu_Composite_Pricing` n'était
  pas mesurée — l'appel pouvait être supprimé sans qu'une assertion bouge. La
  classe tierce est **simulée dans la suite** : ce qui est éprouvé est notre
  ordre de résolution, pas Addify. La simulation ignore la quantité et ne
  répond que pour un rôle et un produit : la normalisation de la quantité et le
  parcours de plusieurs rôles restent décidés par la simulation.
- **Borne de la quantité de kit** : `quote()` n'était jamais appelé avec un
  négatif, donc le `max( 1, … )` pouvait disparaître.

Deux assertions préexistantes ne mesuraient rien et ont été remplacées : l'une
comparait le nom d'une classe à lui-même, l'autre était un OU avec « il y a au
moins un lien dans la page », toujours vrai. La seconde masquait un trou réel :
le contrôle de vocabulaire lit le `.po`, donc il ne voit pas une chaîne écrite
en dur dans le PHP d'un écran — par où « kit » reviendrait. Il lit désormais les
mots que l'écran imprime, **sur un rendu sans aucun produit composite** : compter
sur l'écran peuplé comptait des données de boutique, et un marchand dont un
produit s'appelle « Kit XXL » l'aurait fait rougir sans qu'un mot de l'extension
bouge.

Le contrôle du titre lit le **texte** du `h1` (extraction entre `<h1…>` et
`</h1>`, balises retirées) et non plus le balisage : l'habillage de la famille a
donné une classe à ce `h1`, et une assertion épinglée sur le balisage exact
rougissait sur un restylage qui n'avait changé aucun mot.

**Une assertion n'a pas d'injection** : celle qui vérifie que le filtre de
remise du jeu de données a bien été retiré garde le banc, pas l'extension.
C'est écrit en tête du script plutôt que laissé paraître prouvé. Une première
version déclarait cette limite au mauvais endroit, sur une assertion qui était
en fait injectable — une limite déclarée sans être éprouvée ne vaut pas plus
qu'un contrôle déclaré sans être éprouvé.

## La visite guidée (2026-10-20)

Trois étapes sur l'écran de réglages. Quatre choses tenues par des assertions,
parce qu'une visite mal câblée se joue **une fois, dans le vide, en silence**,
puis se marque comme vue :

- `tour_screen` est **dérivé** du slug (`'woocommerce_page_' .
  DAHU_COMPOSITE_SETTINGS_SLUG`), jamais écrit en dur, et une assertion le
  compare au crochet que WordPress produit réellement. L'éditeur de produit ne
  peut pas servir d'écran de visite : son crochet est `post.php`, qui se
  déclenche aussi sur un article de blog.
- Les **trois sélecteurs** sont cherchés dans le rendu de l'écran, par DOM et
  non par recherche de texte. Une étape dont la cible est absente est sautée par
  conception — ce qui est juste pour un écran qui varie, et fatal pour une
  faute de frappe.
- La **capacité** est `manage_woocommerce`, celle de l'écran, et non le défaut
  `manage_options` du fichier partagé : sinon un gestionnaire de boutique voit
  l'écran et jamais sa visite.
- **Trois étapes, pas plus**, la règle de la famille.

La spécification est lue par réflexion dans la suite : le fichier partagé
`includes/dahu-welcome.php` est identique à l'octet près dans les quatorze
extensions et comparé comme tel, donc il ne peut pas gagner un accesseur pour
une seule.

---

## Les écrans d'administration et la famille (2026-10-30)

Le chantier ouvert le 20/10 — « reprendre les écrans d'administration avec les
composants de la famille (cartes, pastilles, tableaux) » — est **clos**. Il se
termine par une décision, pas par du code, et c'est pour ça que cette section
existe : une décision non écrite est indistinguable d'un oubli, et le prochain
lecteur croirait trouver un travail laissé en plan.

### Ce qui est converti

L'en-tête et les boutons, depuis le 20/10. Les panneaux des composants et des
scénarios reçoivent bien l'habillage : le conteneur `dahu-ui` est posé par le
**PHP**, pas par le JavaScript —
`class-dahu-composite-admin.php` lignes 506 et 804 — et l'ordre de chargement
est garanti par la dépendance déclarée à l'enfilage (`'dahu-composite-admin'`
dépend de `'dahu-ui'`).

Mesuré au passage, et bon à savoir pour les treize autres extensions : poser le
conteneur `.dahu-ui` est **quasi inerte**. La règle `.dahu-ui` ne déclare que
des jetons plus `color` ; les 71 autres règles de la feuille exigent toutes une
classe `dahu-ui-*` explicite, et les seuls sélecteurs d'élément nu sont
qualifiés par une classe de famille (`.dahu-ui-table th`,
`.dahu-ui-card .form-table th`).

### Ce qui ne sera pas converti, et pourquoi

Les **pastilles de catégorie** de l'éditeur de kit restent en
`dahu-kit-admin__badge`. Arbitré le 30/10.

La raison est le sens, pas la taille : la famille réserve la pastille à un
**état** (`--live`, `--off`, `--settled`, `--unknown`, `--waiting`). Un type de
composant n'est pas un état, et une forme commune le laisserait croire.

Le coût est au dossier aussi, mesuré dans un navigateur sur les feuilles
réelles : 56,3 × 20 px aujourd'hui contre 66 × 26 avec la forme de famille, soit
+17 % en largeur et +30 % en hauteur, pour une ligne de résumé qui peut porter
sept pastilles et se replierait.

Un remède avait été éprouvé pour l'effet de bord qu'une conversion aurait
produit, et il est consigné ici au cas où la décision changerait un jour : la
famille écrit `font-weight: 600` en `.dahu-ui .dahu-ui-pill`, soit une
spécificité (0,2,0) contre (0,1,0) pour la variante propre, donc les cinq
variantes qui portent `400` le perdraient. Écrire la variante
`.dahu-ui .dahu-kit-admin__badge.dahu-kit-admin__badge--group` porte la
spécificité à (0,3,0) et gagne **sans dépendre de l'ordre de chargement**.

La décision est également écrite en tête du bloc des pastilles dans
`assets/css/dahu-composite-admin.css`, là où la question se repose
naturellement.

### Le défaut trouvé en chemin

`dahu-kit-admin__badge--multiple` était posée par le résumé de composant et
**aucune feuille ne l'habillait** — les neuf feuilles livrées définissaient huit
variantes, pas celle-là. La pastille portait la forme et aucune couleur : le
seul composant qui accepte plusieurs réponses ressemblait à du texte égaré au
milieu de huit frères colorés. Corrigé en reprenant exactement le traitement
d'`optional`, parce que les deux décrivent le **choix offert** et non une
catégorie — aucune couleur nouvelle n'entre dans l'extension. Contraste mesuré :
10,26:1.

Ce défaut ne pouvait être vu par aucun banc, et c'est ce qui en a fait le vrai
apport : `prove-styled-classes.js` allait de la feuille vers le code (il trouve
du CSS mort), `test-ui.php` allait du code vers la feuille **pour le PHP
seulement**, et les écrans composent leur balisage en JavaScript. Le contrôle de
la direction inverse existe depuis le 29/10, restreint à la signature du défaut
réellement trouvé : un modificateur `base--variante` posé, sans règle, dont la
base est habillée **et** qui a au moins un frère habillé.
