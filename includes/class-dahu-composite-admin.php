<?php
/**
 * Admin: kit configuration metabox (components, scenarios, JSON
 * import/export).
 *
 * The interface (assets/js/dahu-composite-admin.js) builds a configuration
 * JSON into a hidden field; when the product is saved, that JSON is
 * normalised by Dahu_Composite_Kit_Config::save(). The same format drives
 * import/export — that is how comparison kits are created on staging (see
 * RESUME_TECHNIQUE.md).
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Admin {

	const NONCE = 'dahu_composite_save_kit';

	/**
	 * Configuration boxes, in display order.
	 *
	 * One WordPress box per major part: the native header brings collapsing,
	 * dragging, per-user persistence and the Screen Options checkbox, without
	 * a single line of code on our side.
	 *
	 * All five share the SAME JavaScript state and the SAME save field: they
	 * are only five mount points, the logic is not duplicated anywhere.
	 *
	 * Exception: the Appearance box. Its two settings are NOT part of the kit
	 * configuration (neither of the JSON nor of import / export): they are
	 * ordinary form fields, saved as separate metas (see
	 * Dahu_Composite_Appearance and save()).
	 *
	 * 'closed' => collapsed on the first visit only (see postbox_classes).
	 */
	const BOXES = array(
		'dahu-kit-appearance-box' => array( 'closed' => false ),
		'dahu-kit-components-box' => array( 'closed' => false ),
		'dahu-kit-scenarios-box'  => array( 'closed' => false ),
		// Customer exceptions: same JavaScript state, same JSON.
		'dahu-kit-exceptions-box' => array( 'closed' => true ),
		'dahu-kit-warnings-box'   => array( 'closed' => false ),
		'dahu-kit-simulator-box'  => array( 'closed' => true ),
		'dahu-kit-io-box'         => array( 'closed' => true ),
	);

	/**
	 * Box titles, keyed by box id.
	 *
	 * They cannot be translated in the constant above: a PHP constant
	 * expression may not call a function. Spelling them out here as literal
	 * __() calls is also what lets the string extractor find them.
	 *
	 * @return array<string,string>
	 */
	/**
	 * Labels of the configuration interface, handed to the script.
	 *
	 * The script never writes a label of its own: it reads this table
	 * (DahuCompositeAdmin.i18n) through its L() helper, which falls back to an
	 * English string baked into the file when a key is missing — so the
	 * interface still reads correctly if the localisation fails to load.
	 *
	 * The keys are an interface: they are grouped by family below, and a
	 * released key is not renamed. A count string comes back as the two forms
	 * _n() registered for the locale, as array( singular, plural ), and the
	 * script picks on n === 1; a string with several placeholders uses the
	 * positional %1$s… form so a translation may reorder them.
	 *
	 * @return array<string,string|string[]>
	 */
	private static function script_i18n() {
		return array(
			// Shared actions and labels, reused by several boxes.
			'moveUp'                    => __( 'Move up', 'dahu-composite' ),
			'moveDown'                  => __( 'Move down', 'dahu-composite' ),
			'duplicate'                 => __( 'Duplicate', 'dahu-composite' ),
			'delete'                    => __( 'Delete', 'dahu-composite' ),
			'change'                    => __( 'Change', 'dahu-composite' ),
			'remove'                    => __( 'Remove', 'dahu-composite' ),
			'choose'                    => __( 'Choose', 'dahu-composite' ),
			'image'                     => __( 'Image', 'dahu-composite' ),
			'media'                     => __( 'Media', 'dahu-composite' ),
			'labelField'                => __( 'Label', 'dahu-composite' ),
			'titleField'                => __( 'Title', 'dahu-composite' ),
			'badgeField'                => __( 'Badge', 'dahu-composite' ),
			'identifier'                => __( 'Identifier', 'dahu-composite' ),
			'identifierFromLabel'       => __( 'generated from the label', 'dahu-composite' ),
			'colourField'               => __( 'Colour', 'dahu-composite' ),
			'optionNone'                => __( '— None —', 'dahu-composite' ),
			'noLabel'                   => __( '(no label)', 'dahu-composite' ),
			'untitled'                  => __( '(untitled)', 'dahu-composite' ),
			'none'                      => __( 'None', 'dahu-composite' ),
			'yes'                       => __( 'yes', 'dahu-composite' ),
			'no'                        => __( 'no', 'dahu-composite' ),
			'copy'                      => __( 'Copy', 'dahu-composite' ),
			'copied'                    => __( 'Copied', 'dahu-composite' ),
			'copyFailed'                => __( 'Copy failed', 'dahu-composite' ),
			'dragToReorder'             => __( 'Drag to reorder', 'dahu-composite' ),
			'cannotBeUndone'            => __( 'This cannot be undone without reloading the page.', 'dahu-composite' ),
			'httpError'                 => __( 'Error %s', 'dahu-composite' ),
			'notFound'                  => __( '%s (not found)', 'dahu-composite' ),

			// Component box: type, choice mode, screen and quantity.
			'componentsBox'             => __( 'Components', 'dahu-composite' ),
			'componentType'             => __( 'Component type', 'dahu-composite' ),
			'typeProducts'              => __( 'Products', 'dahu-composite' ),
			'typeBranch'                => __( 'Branch (no product)', 'dahu-composite' ),
			'choiceField'               => __( 'Choice', 'dahu-composite' ),
			'choiceSingle'              => __( 'Single (one option)', 'dahu-composite' ),
			'choiceMultiple'            => __( 'Multiple (several options, one row each)', 'dahu-composite' ),
			'layoutField'               => __( 'Layout', 'dahu-composite' ),
			'styleDefault'              => __( 'Default (the kit’s general appearance)', 'dahu-composite' ),
			'styleImage'                => __( 'With image — cards in a grid', 'dahu-composite' ),
			'styleRow'                  => __( 'Inline — one expandable row per option', 'dahu-composite' ),
			'styleSmall'                => __( 'Small — compact grid', 'dahu-composite' ),
			'styleIcon'                 => __( 'Category icon', 'dahu-composite' ),
			'styleChips'                => __( 'Tickable chips', 'dahu-composite' ),
			'displayGroup'              => __( 'Display group', 'dahu-composite' ),
			'displayGroupPlaceholder'   => __( 'same value = same screen', 'dahu-composite' ),
			'titleRoleField'            => __( 'Title on the screen', 'dahu-composite' ),
			'titleRolePrimary'          => __( 'Main (in the screen title)', 'dahu-composite' ),
			'titleRoleSecondary'        => __( 'Secondary (add-on, no title)', 'dahu-composite' ),
			'presentationField'         => __( 'Add-on presentation', 'dahu-composite' ),
			'presentationCard'          => __( '“Select” card', 'dahu-composite' ),
			'presentationQuestion'      => __( 'Yes / no question', 'dahu-composite' ),
			'questionField'             => __( 'Text of the question', 'dahu-composite' ),
			'questionPlaceholder'       => __( 'Empty: the title of the component', 'dahu-composite' ),
			'quantityEditable'          => __( 'Adjustable quantity', 'dahu-composite' ),
			'quantityMin'               => __( 'Min quantity', 'dahu-composite' ),
			'quantityMax'               => __( 'Max quantity', 'dahu-composite' ),
			'optionalField'             => __( 'Optional', 'dahu-composite' ),
			'shippedIndividually'       => __( 'Shipped on its own (otherwise its weight is added to the kit parcel)', 'dahu-composite' ),
			'weightModeField'           => __( 'Displayed weight', 'dahu-composite' ),
			'weightModeProducts'        => __( 'Weight of the products', 'dahu-composite' ),
			'weightModeFixed'           => __( 'Fixed value', 'dahu-composite' ),
			'weightModeSubtract'        => __( 'Weight of the products minus…', 'dahu-composite' ),
			'weightGrams'               => __( 'Grams', 'dahu-composite' ),
			'descriptionField'          => __( 'Description (HTML allowed)', 'dahu-composite' ),
			'infoBubbleText'            => __( 'Info bubble text (optional)', 'dahu-composite' ),
			'optionsProductsField'      => __( 'Options (products)', 'dahu-composite' ),
			'searchProduct'             => __( 'Search for a product…', 'dahu-composite' ),
			'defaultSelectionField'     => __( 'Default selection', 'dahu-composite' ),
			'defaultOptionField'        => __( 'Default option', 'dahu-composite' ),

			// Component box: badges of the folded header.
			'badgeBranch'               => __( 'Branch', 'dahu-composite' ),
			'badgeProducts'             => __( 'Products', 'dahu-composite' ),
			'badgeMultiple'             => __( 'multiple choice', 'dahu-composite' ),
			'badgeOptional'             => __( 'optional', 'dahu-composite' ),
			'badgeScreen'               => __( 'screen: %s', 'dahu-composite' ),
			'optionCount'               => __( 'Options: %s', 'dahu-composite' ),
			'rangeCount'                => __( 'Ranges: %s', 'dahu-composite' ),
			'restrictedCount'           => __( 'Restricted: %s', 'dahu-composite' ),
			'everythingPublic'          => __( 'everything is public', 'dahu-composite' ),

			// Anchoring an add-on to the card of another component.
			'anchorNextTo'              => __( 'Anchor next to', 'dahu-composite' ),
			'anchorAutomatic'           => __( '— Automatic (the chosen card of the first component on the screen) —', 'dahu-composite' ),
			'anchorOtherScreen'         => __( '%s (other screen)', 'dahu-composite' ),
			'anchorCard'                => __( 'Card', 'dahu-composite' ),
			'anchorChosenCard'          => __( '— Chosen card —', 'dahu-composite' ),
			'anchorAbsent'              => __( '%s (absent from the options)', 'dahu-composite' ),
			'anchorNote'                => __( 'The add-on appears after the anchor card (same screen).', 'dahu-composite' ),

			// Categories (ranges) of a component.
			'rangesPanel'               => __( 'Categories / ranges (%s)', 'dahu-composite' ),
			'rangesHelp'                => __( 'One section per category, in this order.', 'dahu-composite' ),
			'rangesHelpEmpty'           => __( '“Range display” appears above as soon as there is one.', 'dahu-composite' ),
			'rangeAdd'                  => __( 'Add a category', 'dahu-composite' ),
			'rangeCreate'               => __( 'Create a category', 'dahu-composite' ),
			'rangeField'                => __( 'Range', 'dahu-composite' ),
			'rangeNoCategory'           => __( 'Range: no category.', 'dahu-composite' ),
			'rangeDisplay'              => __( 'Display of the ranges:', 'dahu-composite' ),
			'rangeNewline'              => __( 'On its own line', 'dahu-composite' ),
			'rangeBadge'                => __( 'Badge', 'dahu-composite' ),
			'rangeTint'                 => __( 'Coloured background', 'dahu-composite' ),
			'colourDefault'             => __( 'Default (%s)', 'dahu-composite' ),
			'tierMedia'                 => __( 'Tooltip image or video (optional)', 'dahu-composite' ),
			'tierMediaUrl'              => __( 'or a video link (YouTube, Vimeo, .mp4 file)', 'dahu-composite' ),
			'chooseFromLibrary'         => __( 'Choose from the media library', 'dahu-composite' ),

			// Product option: display, variations, help image, visibility.
			'displayedTitle'            => __( 'Displayed title', 'dahu-composite' ),
			'badgePlaceholder'          => __( 'e.g. Best seller', 'dahu-composite' ),
			'displayedDescription'      => __( 'Displayed description (simple HTML)', 'dahu-composite' ),
			'showImage'                 => __( 'Show the image', 'dahu-composite' ),
			'showDescription'           => __( 'Description', 'dahu-composite' ),
			'showSpecs'                 => __( 'Specifications', 'dahu-composite' ),
			'showBundled'               => __( 'Included products', 'dahu-composite' ),
			'customiseDisplay'          => __( 'Customise the display', 'dahu-composite' ),
			'customiseDisplayHelp'      => __( 'Empty: the product’s own data.', 'dahu-composite' ),
			'changeImage'               => __( 'Change the image', 'dahu-composite' ),
			'chooseImage'               => __( 'Choose an image', 'dahu-composite' ),
			'everyVariant'              => __( 'Every variant', 'dahu-composite' ),
			'helpImagePanel'            => __( 'Help image', 'dahu-composite' ),
			'helpImageHelp'             => __( 'On the back of the card, at the top of the description.', 'dahu-composite' ),
			'variantsPanel'             => __( 'Variations offered', 'dahu-composite' ),
			'variantsAll'               => __( 'all', 'dahu-composite' ),
			'variantsRestrictedCount'   => __( 'restricted on %s', 'dahu-composite' ),
			'variantsHelp'              => __( 'Unticked: the variation is no longer offered.', 'dahu-composite' ),
			'visibilityPanel'           => __( 'Public visibility', 'dahu-composite' ),
			'visibilityHelp'            => __( 'Unticked: restricted to customers with an exception.', 'dahu-composite' ),
			'publicLabel'               => __( 'public', 'dahu-composite' ),
			'restricted'                => __( 'restricted', 'dahu-composite' ),
			'restrictedEveryVariant'    => __( 'restricted (every variant)', 'dahu-composite' ),
			'noneCard'                  => __( '“None” card', 'dahu-composite' ),
			'noneCardHelp'              => __( 'Empty: the shared image (WooCommerce › Configurable kits).', 'dahu-composite' ),
			'valueNoSelection'          => __( '— No selection —', 'dahu-composite' ),
			'valueEveryVariant'         => __( '%s — every variant', 'dahu-composite' ),
			'valueVariantPending'       => __( '%s — variant not chosen yet', 'dahu-composite' ),

			// Options of a branch component.
			'branchOptions'             => __( 'Options of the branch', 'dahu-composite' ),
			'addOption'                 => __( 'Add an option', 'dahu-composite' ),
			'deleteThisOption'          => __( 'Delete this option', 'dahu-composite' ),
			'badgePathPlaceholder'      => __( 'e.g. New', 'dahu-composite' ),
			'comingSoon'                => __( 'Coming soon', 'dahu-composite' ),
			'linkField'                 => __( 'Link (optional)', 'dahu-composite' ),
			'linkPlaceholder'           => __( 'page slug (contact) or https://…', 'dahu-composite' ),
			'linkTitle'                 => __( 'Filled in: the card is no longer a choice, it opens this page.', 'dahu-composite' ),

			// Media library frames (title and confirm button).
			'mediaOptionImage'          => __( 'Image of the option', 'dahu-composite' ),
			'mediaHelpImage'            => __( 'Help image (back of the card)', 'dahu-composite' ),
			/* translators: %s is the title of the component. */
			'mediaNoneImage'            => __( 'Image of the “None” card — %s', 'dahu-composite' ),
			'mediaTierTooltip'          => __( 'Tooltip image or video “%s”', 'dahu-composite' ),
			'mediaUseThisVisual'        => __( 'Use this visual', 'dahu-composite' ),
			'mediaUseThisImage'         => __( 'Use this image', 'dahu-composite' ),
			'mediaUseThisMedia'         => __( 'Use this media', 'dahu-composite' ),

			// Duplicating and deleting.
			/* translators: %s is the name of the item being duplicated. */
			'copySuffix'                => __( '%s (copy)', 'dahu-composite' ),
			'tierDeleteConfirm'         => __( 'Delete the category “%s”?', 'dahu-composite' ),
			'tierDeleteAttached'        => array(
				_n( '%s option is attached to it: it will go back to having no category.', '%s options are attached to it: they will go back to having no category.', 1, 'dahu-composite' ),
				_n( '%s option is attached to it: it will go back to having no category.', '%s options are attached to it: they will go back to having no category.', 2, 'dahu-composite' ),
			),
			'tierDeleteNoOption'        => __( 'No option is attached to it.', 'dahu-composite' ),
			'componentDeleteConfirm'    => __( 'Delete the component “%s”?', 'dahu-composite' ),
			'componentDeleteLinked'     => array(
				_n( '%s scenario refers to it and will be deleted, or have this condition removed.', '%s scenarios refer to it and will be deleted, or have this condition removed.', 1, 'dahu-composite' ),
				_n( '%s scenario refers to it and will be deleted, or have this condition removed.', '%s scenarios refer to it and will be deleted, or have this condition removed.', 2, 'dahu-composite' ),
			),
			'scenarioDeleteConfirm'     => __( 'Delete the scenario “%s”?', 'dahu-composite' ),

			// Import and export of the configuration JSON.
			'importInvalidJson'         => __( 'Invalid JSON: %s', 'dahu-composite' ),
			'importNoComponents'        => __( 'Invalid JSON: the “components” key is missing.', 'dahu-composite' ),
			'importDone'                => __( 'Configuration imported: save the product to apply it.', 'dahu-composite' ),
			'importKept'                => __( 'Already set in the admin and absent from the imported JSON, so kept: %s.', 'dahu-composite' ),
			'keptImages'                => __( '%s image(s)', 'dahu-composite' ),
			'keptHelpImages'            => __( '%s help image(s)', 'dahu-composite' ),
			'keptOptionTitles'          => __( '%s option title(s)', 'dahu-composite' ),
			'keptOptionLabels'          => __( '%s option label(s)', 'dahu-composite' ),
			'keptOptionDescriptions'    => __( '%s option description(s)', 'dahu-composite' ),
			'keptBadges'                => __( '%s badge(s)', 'dahu-composite' ),
			'keptComponentTitles'       => __( '%s component title(s)', 'dahu-composite' ),
			'keptComponentLayouts'      => __( '%s component layout(s)', 'dahu-composite' ),
			'keptComponentDescriptions' => __( '%s component description(s)', 'dahu-composite' ),
			'keptComponentInfo'         => __( '%s component info bubble text(s)', 'dahu-composite' ),
			'keptNoneImages'            => __( '%s “None” card image(s)', 'dahu-composite' ),
			'keptRangeInfo'             => __( '%s range info bubble text(s)', 'dahu-composite' ),
			'keptRangeMedia'            => __( '%s range media', 'dahu-composite' ),
			'keptRangeVideoLinks'       => __( '%s range video link(s)', 'dahu-composite' ),
			'keptCardLinks'             => __( '%s card link(s)', 'dahu-composite' ),
			'keptOfferedVariations'     => __( '%s offered variation choices', 'dahu-composite' ),
			'keptVariantHelpImages'     => __( '%s variant help images', 'dahu-composite' ),
			'keptRangeColours'          => __( '%s range colour(s)', 'dahu-composite' ),
			'keptRangeNewline'          => __( '%s “On its own line” setting(s)', 'dahu-composite' ),
			'keptRangeBadge'            => __( '%s “Badge” setting(s)', 'dahu-composite' ),
			'keptRangeTint'             => __( '%s “Coloured background” setting(s)', 'dahu-composite' ),

			// Scenarios box: fields, actions and conditions.
			'scenariosBox'              => __( 'Scenarios', 'dahu-composite' ),
			'targetComponentField'      => __( 'Target component', 'dahu-composite' ),
			'actionField'               => __( 'Action', 'dahu-composite' ),
			'actionHideOptions'         => __( 'Hide options', 'dahu-composite' ),
			'actionShowOnly'            => __( 'Show only these options', 'dahu-composite' ),
			'actionHideStep'            => __( 'Hide the whole step', 'dahu-composite' ),
			'actionDeadEnd'             => __( 'Dead end (stop the configurator with a message)', 'dahu-composite' ),
			'blockMessageField'         => __( 'Message shown (dead end)', 'dahu-composite' ),
			'restartField'              => __( 'Resume at step', 'dahu-composite' ),
			'scenMaskedField'           => __( 'Options to hide in “%s”', 'dahu-composite' ),
			'scenShownField'            => __( 'Options to keep visible in “%s”', 'dahu-composite' ),
			'scenShownHelp'             => __( 'The other options are hidden when the conditions are true.', 'dahu-composite' ),
			'condOn'                    => __( 'Condition on', 'dahu-composite' ),
			'condIsOneOf'               => __( 'is one of', 'dahu-composite' ),
			'condIsNoneOf'              => __( 'is none of', 'dahu-composite' ),
			'condHelp'                  => __( 'Active when every condition is true.', 'dahu-composite' ),
			'condNone'                  => __( 'No condition possible: always active.', 'dahu-composite' ),
			/* translators: %s is the customer condition, written out. */
			'condCustomerLine'          => __( 'Customer condition: %s (import JSON).', 'dahu-composite' ),
			'badgeDeadEnd'              => __( 'Dead end', 'dahu-composite' ),
			'badgeHidesStep'            => __( 'Hides the step', 'dahu-composite' ),
			'badgeShowsOnly'            => __( 'Shows only', 'dahu-composite' ),
			'badgeHidesOptions'         => __( 'Hides options', 'dahu-composite' ),
			'filterEveryTarget'         => __( 'Every target', 'dahu-composite' ),
			'targetMissing'             => __( '(target missing)', 'dahu-composite' ),

			// Scenarios box: readable sentence of a scenario.
			'scenDeadEnd'               => __( 'Dead end', 'dahu-composite' ),
			'scenHidesStep'             => __( 'Hides the step %s', 'dahu-composite' ),
			'scenHidesOptions'          => __( 'Hides options of %s', 'dahu-composite' ),
			/* translators: 1: name of the target component, 2: the list of options kept visible. */
			'scenShowsOnly'             => __( 'Shows in %1$s only %2$s', 'dahu-composite' ),
			'scenNoOptionChosen'        => __( '(no option chosen)', 'dahu-composite' ),
			'scenNoValue'               => __( '%s (no value)', 'dahu-composite' ),
			/* translators: 1: what the scenario does, 2: the list of its conditions. */
			'scenIf'                    => __( '%1$s if %2$s', 'dahu-composite' ),
			/* translators: joins two conditions of a scenario; keep the surrounding spaces. */
			'scenAnd'                   => __( ' and ', 'dahu-composite' ),
			'scenAlwaysActive'          => __( '%s (always active)', 'dahu-composite' ),
			'customerNumber'            => __( 'customer #%s', 'dahu-composite' ),
			'customerIn'                => __( 'customer ∈ {%s}', 'dahu-composite' ),
			'customerNotIn'             => __( 'customer ∉ {%s}', 'dahu-composite' ),

			// Customer exceptions and the rules the server generates from them.
			'exceptionsBox'             => __( 'Customer exceptions', 'dahu-composite' ),
			'excNone'                   => __( 'No exception.', 'dahu-composite' ),
			'customerField'             => __( 'Customer', 'dahu-composite' ),
			'searchCustomer'            => __( 'Search for a customer (name, e-mail)…', 'dahu-composite' ),
			'componentField'            => __( 'Component', 'dahu-composite' ),
			'noteField'                 => __( 'Note (internal)', 'dahu-composite' ),
			'notePlaceholder'           => __( 'e.g. loyal customer, agreement of 12/09', 'dahu-composite' ),
			'excAccessAll'              => __( 'Access to every restricted option and variation of this component', 'dahu-composite' ),
			'excAccessList'             => __( 'Only these, on top of the public ones:', 'dahu-composite' ),
			'excNothingRestricted'      => __( 'Nothing is restricted on this component.', 'dahu-composite' ),
			/* translators: 1: name of the customer, 2: name of the component. */
			'excSentenceAll'            => __( '%1$s — %2$s: every restricted option and variation', 'dahu-composite' ),
			/* translators: 1: name of the customer, 2: name of the component, 3: number of reserved values. */
			'excSentenceList'           => array(
				_n( '%1$s — %2$s: %3$s reserved value on top of the public ones', '%1$s — %2$s: %3$s reserved values on top of the public ones', 1, 'dahu-composite' ),
				_n( '%1$s — %2$s: %3$s reserved value on top of the public ones', '%1$s — %2$s: %3$s reserved values on top of the public ones', 2, 'dahu-composite' ),
			),
			'reservedValues'            => array(
				_n( '%s reserved value', '%s reserved values', 1, 'dahu-composite' ),
				_n( '%s reserved value', '%s reserved values', 2, 'dahu-composite' ),
			),
			'nothingRestricted'         => __( 'nothing restricted', 'dahu-composite' ),
			'everyVariantOfIt'          => __( 'every one of its variants', 'dahu-composite' ),
			'unknownComponent'          => __( '(component?)', 'dahu-composite' ),
			'customerToChoose'          => __( '(customer to be chosen)', 'dahu-composite' ),
			'generatedTitle'            => __( 'Generated rules (read only)', 'dahu-composite' ),
			'generatedNone'             => __( 'Generated rules: none (nothing is restricted).', 'dahu-composite' ),
			/* translators: 1: number of values hidden, 2: name of the component, 3: who the rule applies to, 4: the values. */
			'generatedLine'             => array(
				_n( ' — hides %1$s value of “%2$s” %3$s: %4$s', ' — hides %1$s values of “%2$s” %3$s: %4$s', 1, 'dahu-composite' ),
				_n( ' — hides %1$s value of “%2$s” %3$s: %4$s', ' — hides %1$s values of “%2$s” %3$s: %4$s', 2, 'dahu-composite' ),
			),
			'generatedForEveryone'      => __( 'for everyone', 'dahu-composite' ),
			'generatedOnlyFor'          => __( 'for %s only', 'dahu-composite' ),
			'generatedExceptFor'        => __( 'for everyone, except %s', 'dahu-composite' ),

			// Simulator: journey, totals and configuration serial.
			'simComputing'              => __( 'Computing…', 'dahu-composite' ),
			'simNoStepVisible'          => __( 'No step is visible with these choices.', 'dahu-composite' ),
			/* translators: 1: screen number, 2: title of the screen. */
			'simScreenTitle'            => __( 'Screen %1$s — %2$s', 'dahu-composite' ),
			'simSummaryStep'            => __( 'Summary', 'dahu-composite' ),
			/* translators: 1: number of steps, 2: the steps, joined by an arrow. */
			'simPath'                   => array(
				_n( 'Journey shown to the customer (%1$s step): %2$s', 'Journey shown to the customer (%1$s steps): %2$s', 1, 'dahu-composite' ),
				_n( 'Journey shown to the customer (%1$s step): %2$s', 'Journey shown to the customer (%1$s steps): %2$s', 2, 'dahu-composite' ),
			),
			'simDeadEnd'                => __( 'Dead end.', 'dahu-composite' ),
			'simRestartAt'              => __( 'Resume offered at step: %s', 'dahu-composite' ),
			'simOptionsUnavailable'     => __( 'Options unavailable (component renamed?).', 'dahu-composite' ),
			'simNoOptionAvailable'      => __( 'No option available with these choices.', 'dahu-composite' ),
			'simVariantOf'              => __( '— Variant of %s —', 'dahu-composite' ),
			'simChooseVariant'          => __( '— Choose a variant —', 'dahu-composite' ),
			'simStepToFill'             => __( 'Step to fill in.', 'dahu-composite' ),
			'simTotal'                  => __( 'Total:', 'dahu-composite' ),
			/* translators: 1: weight, 2: unit of weight. */
			'simWeight'                 => __( 'Estimated weight: %1$s %2$s', 'dahu-composite' ),
			'simValid'                  => __( 'Configuration complete and valid', 'dahu-composite' ),
			'simIncomplete'             => __( 'Configuration incomplete', 'dahu-composite' ),
			'simReset'                  => __( 'Reset', 'dahu-composite' ),
			'simSerialLabel'            => __( 'Configuration serial:', 'dahu-composite' ),
			'simSerialPartialLabel'     => __( 'Configuration serial (partial configuration):', 'dahu-composite' ),
			'simSerialNone'             => __( 'no serial as long as no choice is made.', 'dahu-composite' ),
			'simOpenLink'               => __( 'Open on the site', 'dahu-composite' ),
			'simOpenTitle'              => __( 'Reopens the configurator on these choices (the kit’s saved configuration)', 'dahu-composite' ),
			'simOpenHelp'               => __( 'An “Open on the site” link once the kit is published.', 'dahu-composite' ),
			'simViewVisitor'            => __( 'Visitor / customer with no exception', 'dahu-composite' ),
			'simViewAs'                 => __( 'View as:', 'dahu-composite' ),
			'simViewAsHelp'             => __( '(this customer’s hidden options; prices of the logged-in account)', 'dahu-composite' ),
			'warningsBox'               => __( 'Warnings', 'dahu-composite' ),
			'warningsTitle'             => __( 'Worth checking', 'dahu-composite' ),

			// Simulator: sentence describing the appearance in force.
			/* translators: 1: card style, 2: corners, 3: icons, 4: info on desktop, 5: info on mobile, 6: click. */
			'appearanceSentence'        => __( 'Appearance on the product page: %1$s, corners %2$s, icons %3$s, info %4$s (desktop) / %5$s (mobile), click %6$s.', 'dahu-composite' ),
			'appearanceCard'            => __( 'Card', 'dahu-composite' ),
			'appearanceThumbnail'       => __( 'Thumbnail', 'dahu-composite' ),
			'appearanceRounded'         => __( 'rounded', 'dahu-composite' ),
			'appearanceSquare'          => __( 'square', 'dahu-composite' ),
			'appearanceInfoSide'        => __( 'side', 'dahu-composite' ),
			'appearanceInfoCard'        => __( 'card', 'dahu-composite' ),
			'appearanceInfoExtended'    => __( 'extended', 'dahu-composite' ),
		);
	}

	private static function box_titles() {
		return array(
			'dahu-kit-appearance-box' => __( 'Appearance', 'dahu-composite' ),
			'dahu-kit-components-box' => __( 'Components', 'dahu-composite' ),
			'dahu-kit-scenarios-box'  => __( 'Scenarios', 'dahu-composite' ),
			'dahu-kit-exceptions-box' => __( 'Customer exceptions', 'dahu-composite' ),
			'dahu-kit-warnings-box'   => __( 'Warnings', 'dahu-composite' ),
			'dahu-kit-simulator-box'  => __( 'Simulator', 'dahu-composite' ),
			'dahu-kit-io-box'         => __( 'Import / export', 'dahu-composite' ),
		);
	}

	public static function init() {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		// Nonce and save field: outside the boxes (see the method).
		add_action( 'edit_form_after_editor', array( __CLASS__, 'render_save_field' ) );
		add_action( 'wp_ajax_dahu_composite_product_info', array( __CLASS__, 'ajax_product_info' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function add_meta_box() {
		$callbacks = array(
			'dahu-kit-appearance-box' => 'render_appearance_box',
			'dahu-kit-components-box' => 'render_components_box',
			'dahu-kit-scenarios-box'  => 'render_scenarios_box',
			'dahu-kit-exceptions-box' => 'render_exceptions_box',
			'dahu-kit-warnings-box'   => 'render_warnings_box',
			'dahu-kit-simulator-box'  => 'render_simulator_box',
			'dahu-kit-io-box'         => 'render_io_box',
		);

		$titles = self::box_titles();

		foreach ( array_keys( self::BOXES ) as $id ) {
			add_meta_box(
				$id,
				/* translators: %s: box name. */
				sprintf( __( '%s (Dahu Composite)', 'dahu-composite' ), $titles[ $id ] ),
				array( __CLASS__, $callbacks[ $id ] ),
				'product',
				'normal',
				'high'
			);

			// Shared class: the script hides all five boxes at once
			// when the product is not a kit.
			add_filter(
				'postbox_classes_product_' . $id,
				function ( $classes ) use ( $id ) {
					$classes[] = 'dahu-kit-postbox';
					// Collapsed by default, on the FIRST visit only: as soon
					// as the user has collapsed or expanded anything on this
					// screen, WordPress has saved that choice and it is the
					// one that counts.
					if ( self::BOXES[ $id ]['closed'] && false === get_user_option( 'closedpostboxes_product' ) ) {
						$classes[] = 'closed';
					}
					return $classes;
				}
			);
		}
	}

	/**
	 * @param string $hook Screen.
	 */
	public static function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		$post_id = (int) get_the_ID();
		// Per-option image picker (wp.media).
		wp_enqueue_media();
		wp_enqueue_style( 'dahu-composite-admin', DAHU_COMPOSITE_URL . 'assets/css/dahu-composite-admin.css', array(), DAHU_COMPOSITE_VERSION );
		// Simulator category sections: same module as the front end.
		wp_register_script( 'dahu-composite-tiers', DAHU_COMPOSITE_URL . 'assets/js/dahu-composite-tiers.js', array(), DAHU_COMPOSITE_VERSION, true );
		wp_enqueue_script( 'dahu-composite-admin', DAHU_COMPOSITE_URL . 'assets/js/dahu-composite-admin.js', array( 'jquery', 'wc-enhanced-select', 'dahu-composite-tiers' ), DAHU_COMPOSITE_VERSION, true );

		// The data used to live in the rendering of the single metabox. It is
		// hoisted here: there are now five boxes, and none of them can claim
		// to be the one that holds the state.
		self::localize( $post_id );
	}

	/**
	 * Configuration handed to the script. Once only, for all five boxes.
	 *
	 * @param int $post_id Product.
	 */
	private static function localize( $post_id ) {
		$config = array(
			'components'          => Dahu_Composite_Kit_Config::get_components( $post_id ),
			'scenarios'           => Dahu_Composite_Kit_Config::get_scenarios( $post_id ),
			'customer_exceptions' => Dahu_Composite_Kit_Config::get_customer_exceptions( $post_id ),
		);

		// Customer labels of the exceptions; the ones chosen afterwards
		// come in through the WooCommerce customer search.
		$customers = array();
		foreach ( $config['customer_exceptions'] as $exception ) {
			$customers[ (int) $exception['customer_id'] ] = Dahu_Composite_Customer_Access::customer_label( $exception['customer_id'] );
		}

		$ids         = array();
		$media_urls  = array();
		$media_names = array();
		foreach ( $config['components'] as $component ) {
			foreach ( $component['options'] as $option ) {
				// The options of a "path" component have no product.
				if ( ! empty( $option['product_id'] ) ) {
					$ids[] = $option['product_id'];
				}
				// Front image, back "help image" and those
				// of the variations.
				$media_ids = array();
				foreach ( array( 'display_image_id', 'help_image_id' ) as $media_key ) {
					if ( ! empty( $option[ $media_key ] ) ) {
						$media_ids[] = (int) $option[ $media_key ];
					}
				}
				if ( ! empty( $option['variation_help_images'] ) && is_array( $option['variation_help_images'] ) ) {
					$media_ids = array_merge( $media_ids, array_map( 'intval', array_values( $option['variation_help_images'] ) ) );
				}
				foreach ( $media_ids as $media_id ) {
					$url = wp_get_attachment_image_url( $media_id, 'thumbnail' );
					if ( $url ) {
						$media_urls[ $media_id ] = $url;
					}
				}
			}
			// Image of the component's "None" card.
			if ( ! empty( $component['none_image_id'] ) ) {
				$url = wp_get_attachment_image_url( (int) $component['none_image_id'], 'thumbnail' );
				if ( $url ) {
					$media_urls[ (int) $component['none_image_id'] ] = $url;
				}
			}
			// Media of a range popover: thumbnail for an image;
			// file name for a video (mediaNames).
			foreach ( $component['tiers'] as $tier ) {
				$media_id = ! empty( $tier['media_id'] ) ? (int) $tier['media_id'] : 0;
				if ( ! $media_id ) {
					continue;
				}
				$url = wp_attachment_is_image( $media_id ) ? wp_get_attachment_image_url( $media_id, 'thumbnail' ) : false;
				if ( $url ) {
					$media_urls[ $media_id ] = $url;
				} elseif ( get_attached_file( $media_id ) ) {
					$media_names[ $media_id ] = wp_basename( get_attached_file( $media_id ) );
				}
			}
		}

		wp_localize_script(
			'dahu-composite-admin',
			'DahuCompositeAdmin',
			array(
				'config'      => $config,
				'i18n'        => self::script_i18n(),
				'productInfo' => (object) self::product_info( $ids ),
				'mediaUrls'   => (object) $media_urls,
				'mediaNames'  => (object) $media_names,
				'productType' => DAHU_COMPOSITE_PRODUCT_TYPE,
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'infoNonce'   => wp_create_nonce( 'dahu_composite_product_info' ),
				'searchNonce' => wp_create_nonce( 'search-products' ),
				'customers'   => (object) $customers,
				'export'      => Dahu_Composite_Kit_Config::export( $post_id ),
				// Simulator: same routes as the front end, in
				// "configure online" mode. The wp_rest nonce is what
				// authenticates the call; the capability is checked server side.
				'restUrl'     => esc_url_raw( rest_url( Dahu_Composite_Rest::NAMESPACE_V1 . '/' ) ),
				'restNonce'   => wp_create_nonce( 'wp_rest' ),
				'productId'   => (int) $post_id,
				// Configuration serial: the simulator's "Open on the
				// site" link, published kits only.
				'kitUrl'      => 'publish' === get_post_status( $post_id ) ? get_permalink( $post_id ) : '',
				'serialParam' => Dahu_Composite_Composition::QUERY_ARG,
				// Saved appearance (the simulator displays it, then follows
				// the Appearance box buttons before saving).
				'appearance'  => Dahu_Composite_Appearance::get( $post_id ),
			)
		);
	}

	/**
	 * Nonce and save field, rendered INSIDE the form but OUTSIDE the boxes.
	 *
	 * That is what makes collapsing harmless: saving reads no input field of
	 * the interface, only this single JSON, written by the script from its
	 * in-memory state. A collapsed box — or even one hidden through Screen
	 * Options — therefore cannot cause anything to be lost.
	 *
	 * Moving it out of the boxes closes the last hole: a box unchecked in
	 * Screen Options does stay in the page, but that is not something to rely
	 * on for saving to work.
	 */
	public static function render_save_field() {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		wp_nonce_field( self::NONCE, self::NONCE );
		echo '<textarea name="dahu_kit_config_json" id="dahu-kit-config-json" hidden></textarea>';
	}

	/**
	 * Configurator appearance for THIS product (Dahu_Composite_Appearance).
	 * Ordinary form fields, outside the configuration JSON.
	 */
	public static function render_appearance_box() {
		$appearance = Dahu_Composite_Appearance::get( (int) get_the_ID() );
		$choices    = array(
			'card_style'   => array(
				'label'   => __( 'Card style', 'dahu-composite' ),
				'options' => array(
					'vignette' => __( 'Thumbnail — photo, subtitle and “More info”, no right-hand column', 'dahu-composite' ),
					'card'     => __( 'Card — fixed-size cards, preview in the right-hand column', 'dahu-composite' ),
				),
			),
			'card_corners' => array(
				'label'   => __( 'Corner shape', 'dahu-composite' ),
				'options' => array(
					'square'  => __( 'Square — summary and right-hand column with no rounding', 'dahu-composite' ),
					'rounded' => __( 'Rounded — summary and right-hand column rounded', 'dahu-composite' ),
				),
			),
			'icons'        => array(
				'label'   => __( 'Icons', 'dahu-composite' ),
				'options' => array(
					'on'  => __( 'Yes — card footer as icons (description, specifications, included products)', 'dahu-composite' ),
					'off' => __( 'No — a “More info” button, sections shown as tabs once opened', 'dahu-composite' ),
				),
			),
			'info_desktop' => array(
				'label'   => __( 'Info display (desktop)', 'dahu-composite' ),
				'options' => array(
					'card'     => __( 'Card — the card flips over', 'dahu-composite' ),
					'lateral'  => __( 'Side — a drawer slides in from the side', 'dahu-composite' ),
					'extended' => __( 'Expanded — the card widens over its neighbours', 'dahu-composite' ),
				),
			),
			'info_mobile'  => array(
				'label'   => __( 'Info display (mobile, below 64em)', 'dahu-composite' ),
				'options' => array(
					'lateral'  => __( 'Side — a drawer slides in from the side', 'dahu-composite' ),
					'card'     => __( 'Card — the card flips over', 'dahu-composite' ),
					'extended' => __( 'Expanded — falls back to a card when space runs short', 'dahu-composite' ),
				),
			),
			'click'        => array(
				'label'   => __( 'Click', 'dahu-composite' ),
				'options' => array(
					'on'  => __( 'Yes — a “Select” button on every card', 'dahu-composite' ),
					'off' => __( 'No — clicking anywhere on the card selects it', 'dahu-composite' ),
				),
			),
		);
		?>
		<div class="dahu-kit-admin dahu-kit-admin__appearance">
			<?php foreach ( $choices as $key => $choice ) : ?>
				<fieldset class="dahu-kit-admin__appearance-field">
					<legend><?php echo esc_html( $choice['label'] ); ?></legend>
					<?php foreach ( $choice['options'] as $value => $label ) : ?>
						<label>
							<input type="radio" name="dahu_kit_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $appearance[ $key ], $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Not changed by the JSON import.', 'dahu-composite' ); ?></p>
		</div>
		<?php
	}

	public static function render_components_box() {
		?>
		<div class="dahu-kit-admin">
			<?php self::render_fold_bar( 'components' ); ?>
			<div id="dahu-kit-components"></div>
			<p><button type="button" class="button" id="dahu-kit-add-component"><?php esc_html_e( 'Add a component', 'dahu-composite' ); ?></button></p>
		</div>
		<?php
	}

	public static function render_scenarios_box() {
		?>
		<div class="dahu-kit-admin">
			<div class="dahu-kit-admin__filters">
				<label class="dahu-kit-admin__filter">
					<span class="screen-reader-text"><?php esc_html_e( 'Search scenarios', 'dahu-composite' ); ?></span>
					<input type="search" id="dahu-kit-scenario-search" class="regular-text"
						placeholder="<?php esc_attr_e( 'Search…', 'dahu-composite' ); ?>">
				</label>
				<label class="dahu-kit-admin__filter">
					<span class="screen-reader-text"><?php esc_html_e( 'Filter by target component', 'dahu-composite' ); ?></span>
					<select id="dahu-kit-scenario-target"><option value=""><?php esc_html_e( 'All targets', 'dahu-composite' ); ?></option></select>
				</label>
				<span class="dahu-kit-admin__filter-count" id="dahu-kit-scenario-count"></span>
			</div>
			<?php self::render_fold_bar( 'scenarios' ); ?>
			<div id="dahu-kit-scenarios"></div>
			<p><button type="button" class="button" id="dahu-kit-add-scenario"><?php esc_html_e( 'Add a scenario', 'dahu-composite' ); ?></button></p>
		</div>
		<?php
	}

	/**
	 * Customer exceptions: see Dahu_Composite_Customer_Access.
	 */
	public static function render_exceptions_box() {
		?>
		<div class="dahu-kit-admin">
			<p class="description">
				<?php esc_html_e( 'Makes restricted options visible to a logged-in customer.', 'dahu-composite' ); ?>
			</p>
			<div id="dahu-kit-exceptions"></div>
			<p><button type="button" class="button" id="dahu-kit-add-exception"><?php esc_html_e( 'Add an exception', 'dahu-composite' ); ?></button></p>
			<div id="dahu-kit-generated" class="dahu-kit-admin__generated"></div>
		</div>
		<?php
	}

	public static function render_warnings_box() {
		?>
		<div class="dahu-kit-admin">
			<div id="dahu-kit-warnings" class="dahu-kit-admin__warnings" hidden></div>
		</div>
		<?php
	}

	public static function render_simulator_box() {
		?>
		<div class="dahu-kit-admin">
			<p class="description">
				<?php esc_html_e( 'Tests the configuration on screen, saved or not.', 'dahu-composite' ); ?>
			</p>
			<div id="dahu-kit-simulator"></div>
		</div>
		<?php
	}

	public static function render_io_box() {
		?>
		<div class="dahu-kit-admin">
			<p class="description"><?php esc_html_e( 'Replaces the configuration on screen, which then has to be saved. A field left empty in the JSON keeps its current value.', 'dahu-composite' ); ?></p>
			<textarea id="dahu-kit-import" rows="8" class="large-text code"></textarea>
			<p>
				<button type="button" class="button" id="dahu-kit-import-button"><?php esc_html_e( 'Import', 'dahu-composite' ); ?></button>
				<button type="button" class="button" id="dahu-kit-export-button"><?php esc_html_e( 'Show export', 'dahu-composite' ); ?></button>
				<?php // Downloaded export: same JSON as "Show export", as a file. ?>
				<button type="button" class="button" id="dahu-kit-download-button"><?php esc_html_e( 'Export', 'dahu-composite' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * “Collapse all / Expand all” bar of a section.
	 *
	 * @param string $section components | scenarios.
	 */
	private static function render_fold_bar( $section ) {
		?>
		<p class="dahu-kit-admin__foldbar">
			<button type="button" class="button-link" data-fold-all="<?php echo esc_attr( $section ); ?>"><?php esc_html_e( 'Collapse all', 'dahu-composite' ); ?></button>
			<button type="button" class="button-link" data-unfold-all="<?php echo esc_attr( $section ); ?>"><?php esc_html_e( 'Expand all', 'dahu-composite' ); ?></button>
		</p>
		<?php
	}

	/**
	 * @param WC_Product $product Product.
	 */
	public static function save( $product ) {
		if ( ! $product instanceof WC_Product_Dahu_Kit ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		// Appearance: BEFORE the configuration, which may stop further down
		// (JSON absent or unreadable) — the two saves are
		// independent. Separate metas, never touched by an import.
		$appearance = array();
		foreach ( array_keys( Dahu_Composite_Appearance::SETTINGS ) as $key ) {
			if ( isset( $_POST[ 'dahu_kit_' . $key ] ) ) {
				$appearance[ $key ] = sanitize_key( wp_unslash( $_POST[ 'dahu_kit_' . $key ] ) );
			}
		}
		Dahu_Composite_Appearance::save( $product->get_id(), $appearance );

		if ( ! isset( $_POST['dahu_kit_config_json'] ) || '' === $_POST['dahu_kit_config_json'] ) {
			return;
		}

		// JSON decoded, then fully normalised by Dahu_Composite_Kit_Config.
		$config = json_decode( wp_unslash( $_POST['dahu_kit_config_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $config ) ) {
			self::store_notice( array( __( 'The kit configuration could not be read: nothing was saved.', 'dahu-composite' ) ) );
			return;
		}

		$raw_components = isset( $config['components'] ) && is_array( $config['components'] ) ? $config['components'] : array();
		$raw_scenarios  = isset( $config['scenarios'] ) && is_array( $config['scenarios'] ) ? $config['scenarios'] : array();
		// Customer exceptions: key absent = unchanged.
		$raw_exceptions = isset( $config['customer_exceptions'] ) && is_array( $config['customer_exceptions'] ) ? $config['customer_exceptions'] : null;

		$errors = Dahu_Composite_Kit_Config::save( $product->get_id(), $raw_components, $raw_scenarios, $raw_exceptions );

		// Safety nets: never blocking, the configuration is saved whatever
		// happens. They only point out the places where the input does not
		// mean what one thinks (dead reference, cascade, dead end).
		$checked = Dahu_Composite_Validator::check( $raw_components, $raw_scenarios, $raw_exceptions );
		// check() replays the normalisation: its messages overlap those of
		// save(), hence the deduplication.
		$errors  = array_values( array_unique( array_merge( $errors, $checked['warnings'] ) ) );

		if ( ! Dahu_Composite_Product_Type::get_container_shipping_class_id() ) {
			$errors[] = __( 'The shipping class set for kits (Dahu Composite settings) does not exist: shipping plugins will not recognise this kit.', 'dahu-composite' );
		}

		if ( $errors ) {
			self::store_notice( $errors );
		}
	}

	/**
	 * @param string[] $messages Messages.
	 */
	private static function store_notice( array $messages ) {
		set_transient( 'dahu_composite_notice_' . get_current_user_id(), $messages, 120 );
	}

	public static function notices() {
		$key      = 'dahu_composite_notice_' . get_current_user_id();
		$messages = get_transient( $key );
		if ( ! $messages ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'Dahu Composite', 'dahu-composite' ) . '</strong></p><ul>';
		foreach ( (array) $messages as $message ) {
			echo '<li>' . esc_html( $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Name, type and variations of the products (for the scenario value
	 * lists).
	 *
	 * @param int[] $ids Products.
	 * @return array
	 */
	public static function product_info( array $ids ) {
		$info = array();
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$product = $id ? wc_get_product( $id ) : null;
			if ( ! $product ) {
				continue;
			}
			$variations = array();
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation ) {
						$variations[] = array(
							'id'   => (int) $variation_id,
							'name' => wp_strip_all_tags( wc_get_formatted_variation( $variation, true, false, false ) ),
						);
					}
				}
			}
			$info[ $id ] = array(
				'name'       => wp_strip_all_tags( $product->get_name() ),
				'type'       => $product->get_type(),
				'status'     => $product->get_status(),
				'variations' => $variations,
			);
		}
		return $info;
	}

	public static function ajax_product_info() {
		check_ajax_referer( 'dahu_composite_product_info', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( null, 403 );
		}
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		wp_send_json_success( (object) self::product_info( array_slice( $ids, 0, 200 ) ) );
	}
}
