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

- [ ] **Vérification fonctionnelle sur un WooCommerce réel.** Le portage n'a été
      validé que par analyse statique (lint PHP, `node --check`, comparaison du
      nombre de balises HTML avant/après traduction). Rien n'a été exécuté.
- [ ] Captures d'écran `screenshot-1..4.png`.
- [ ] Vérifier la disponibilité du slug `dahu-composite` sur wordpress.org.
- [ ] Générer les fichiers de traduction française (`.po`/`.mo`) pour que la
      boutique Annad retrouve son interface en français.
- [x] Mécanisme i18n JavaScript : `wp_localize_script`, cohérent avec ce que
      fait déjà le JS du front. 269 clés pour `dahu-composite-admin.js`.
- [ ] Langues à trois formes de pluriel ou plus (polonais, russe, arabe) : le
      choix sur `n === 1` ne les couvre pas. Il faudra `wp.i18n._n()` dans le
      script, donc `wp_set_script_translations()` et des fichiers JSON. Partout
      où un compte pouvait s'écrire sans flexion (« Restricted: 3 ») il l'est
      déjà, ce qui limite le sujet à 6 chaînes.
- [ ] Nettoyer la convention de version : 0.73.0 en première publication
      signale une bêta, ce qui est exact mais mérite d'être assumé dans la
      description.

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
