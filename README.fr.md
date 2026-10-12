# Dahu Composite

Des kits de produits configurables pour WooCommerce, calculés par le serveur.

Développé et maintenu par [Hugo Vial-Jaime — Dahu-Concept](https://dahu-concept.fr).

*La version anglaise de ce document est dans `README.md`. En cas d'écart, c'est
elle qui fait foi : c'est elle qui part sur wordpress.org.*

## À quoi ça sert

Dahu Composite transforme un produit WooCommerce en configurateur guidé. Le
client parcourt des étapes, choisit une option à chacune, et arrive sur un
récapitulatif dont le prix et le poids ont été calculés par le **serveur**.

Ce partage est l'essentiel. Tout ce qu'un visiteur peut changer est revalidé
côté serveur avant d'atteindre le panier : une configuration qui a passé le
configurateur est une configuration que le panier accepte. Rien de ce qu'envoie
un client n'est cru sur parole.

## L'architecture

Un kit est une liste de **composants**. Chaque composant propose des produits
comme **options**, et chaque option porte sa propre variation, ses bornes de
quantité, son traitement du poids et ses réglages d'affichage. Les **scénarios**
décrivent ce qui arrive à un composant selon ce qui a été choisi dans un autre :
masquer des options, n'en garder qu'une partie, ou arrêter le parcours avec un
message.

Le front est un client mince. Il appelle une seule route REST à chaque action du
client :

```
POST /wp-json/dahu-composite/v1/state
     { product_id, selections, quantity }
  -> sélections corrigées, validité, prix, poids, lignes — en une réponse
```

Le serveur corrige les sélections invalidées par un scénario et propage en
interne, sans aller-retour supplémentaire. `/validate` et `/quote` restent des
routes en lecture seule pour des clients headless externes : elles prennent les
sélections telles quelles et ne les corrigent jamais.

### Les routes REST

| Route | Méthode | Accès |
|---|---|---|
| `/schema/{product_id}` | GET | Publique pour un kit publié ; un brouillon exige `edit_post` |
| `/product-tabs/{product_id}` | GET | Idem |
| `/state` | POST | Publique — calcule, n'écrit rien |
| `/validate` | POST | Publique — calcule, n'écrit rien |
| `/quote` | POST | Publique — calcule, n'écrit rien |
| `/add-to-cart` | POST | Publique — écrit dans le panier de session du visiteur lui-même |
| `/saved-guest` | POST | Publique, derrière un nonce dédié, un consentement et un pot de miel |

Les réponses qui portent un prix sortent en `Cache-Control: no-store, private`,
parce qu'un prix dépend du rôle du client.

## Ce que fait l'extension

- Configurateur par étapes, validé côté serveur
- Scénarios : masquage conditionnel, restriction à un sous-ensemble, impasse avec un message
- Prix et poids calculés depuis les options choisies
- Sections par catégorie, avec leurs icônes, leurs couleurs et leurs bulles d'aide
- Exceptions par client, qui débloquent des options restreintes
- Configurations enregistrées dans Mon compte, avec enregistrement par les visiteurs en option
- Simulateur dans l'administration : tester une configuration avant d'enregistrer le produit
- Import et export JSON de la configuration d'un kit
- Compatible avec le stockage des commandes haute performance (HPOS)

## Ce qu'il faut

| | |
|---|---|
| WordPress | 6.0 ou plus |
| PHP | 7.4 ou plus |
| WooCommerce | 7.0 ou plus |
| HPOS | Compatible |

## Les intégrations facultatives

L'extension fonctionne seule. Quand elles sont présentes, elle dialogue aussi
avec **Dahu Pricing** (libellés de prix et paliers par rôle) et avec une
**extension de lots de produits** (un composant qui propose un lot comme
option). Aucune des deux n'est nécessaire, aucune n'est embarquée.

## Thématisation

Le configurateur lit sa palette dans des propriétés CSS personnalisées, chacune avec une valeur de repli claire et neutre : il est lisible sur une page WordPress ordinaire, sans réglage.

| Variable | Repli | Usage |
|---|---|---|
| `--color-surface-2` | `#F6F7F7` | cartes, panneaux |
| `--color-surface-3` | `#ECEEEF` | surfaces internes |
| `--color-on-surface` | `#1D2327` | texte |
| `--color-border` | `#C9CDD1` | bordures, séparateurs |
| `--color-brand-green` | `#D5EB17` | accent (état sélectionné, boutons principaux) |
| `--color-on-brand-green` | `#1C1A1A` | texte sur l'accent |

Un thème impose sa propre palette en définissant ces variables sur `:root` ; ses valeurs l'emportent toujours sur les replis. Pour un thème sombre, il suffit de définir les variables de surface et de texte, et tout le configurateur suit :

```css
:root {
	--color-surface-2: #2F2F2F;
	--color-on-surface: #F5F5F5;
}
```

## Pour les développeurs de thèmes

Reprendre l'habillage du configurateur :

```php
add_filter( 'dahu_composite_theme_provides_styles', '__return_true' );
```

L'extension ne charge alors que sa feuille de style fonctionnelle — position du
tiroir, animation, blocage du défilement, grille des colonnes — et vous laisse
l'habillage.

Rendre les onglets de détail vous-même :

```php
add_filter( 'dahu_composite_product_tabs_callback', static function () {
    return 'my_theme_product_tabs'; // ( WC_Product $product, string[] $slugs )
} );
```

## Les filtres

Tous les filtres exposés par l'extension. Aucun n'est obligatoire ; les valeurs
par défaut sont ce que fait l'extension livrée à elle-même.

| Filtre | Défaut | Ce qu'il change |
|---|---|---|
| `dahu_composite_theme_provides_styles` | `false` | `true` laisse l'habillage au thème et ne garde que la feuille de style fonctionnelle |
| `dahu_composite_product_tabs_callback` | `''` | Nom d'un appelable qui rend les onglets de détail à la place de l'extension |
| `dahu_composite_category_icons` | mots-clés anglais | Table de mots-clés de la mise en page « Icône de catégorie » : le pictogramme est déduit du nom du produit, donc un catalogue dans une autre langue remplace la table |
| `dahu_composite_includes_label` | `Includes` | Libellé de la mention « Comprend » affichée sous un kit dans le panier, la commande et la Store API |
| `dahu_composite_summary_aside_html` | `''` | Bloc HTML à côté du bouton « Ajouter au panier » du récapitulatif |
| `dahu_composite_recap_note` | `''` | Note sous le récapitulatif |
| `dahu_composite_help_placeholder` | `''` | Texte d'attente du panneau d'aide |
| `dahu_composite_option_subtitle` | `''` | Sous-titre d'une carte d'option |
| `dahu_composite_option_generation` | `''` | Badge de génération d'une option |
| `dahu_composite_option_has_specs` | `null` | Si une option a des spécifications à montrer |
| `dahu_composite_option_weight` | le poids du produit | Poids retenu pour le colis du kit |
| `dahu_composite_option_unit_price` | le prix du produit | Prix unitaire d'une option |
| `dahu_composite_option_unit_regular_price` | le prix normal | Prix unitaire normal d'une option |
| `dahu_composite_kit_price_html` | `''` | HTML du prix du kit sur la page produit |
| `dahu_composite_price_tiers` | les paliers trouvés | Paliers tarifaires proposés |
| `dahu_composite_container_shipping_class_slug` | la classe du kit lui-même | Identifiant de classe de livraison du conteneur du kit |
| `dahu_composite_bundle_configuration` | la configuration calculée | Configuration remise à un composant de type lot |
| `dahu_composite_inline_boot_state` | `true` | `false` cesse d'imprimer l'état initial dans la page |
| `dahu_composite_saved_configs_enabled` | `true` | `false` coupe les configurations enregistrées pour un kit |
| `dahu_composite_guest_save_enabled` | WooCommerce peut créer un client | Si un visiteur peut enregistrer une configuration |
| `dahu_composite_guest_save_fields` | nom et adresse e-mail | Champs du formulaire d'enregistrement par un visiteur |
| `dahu_composite_guest_save_limit` | `10` | Enregistrements autorisés par visiteur |
| `dahu_composite_remove_data_on_uninstall` | `false` | `true` efface les définitions de kits et les configurations enregistrées à la désinstallation |

Deux d'entre eux portent du texte et non un comportement, et tous deux sont
documentés avec un exemple complet dans le code source :
`dahu_composite_category_icons`
(`Dahu_Composite_Frontend::category_icons()`) et
`dahu_composite_includes_label` (`Dahu_Composite_Included::includes_label()`).

## À la désinstallation

Supprimer l'extension supprime ses réglages et ses caches. Les définitions de
kits et les configurations enregistrées par les clients **restent**, parce
qu'une désinstallation n'est souvent qu'une étape d'une réinstallation. Pour
tout effacer, mettez `dahu_composite_remove_data_on_uninstall` à une valeur
vraie avant de supprimer.

## Services externes

Cette extension ne contacte aucun service externe.

## Licence

MIT — voir [LICENSE](LICENSE).
