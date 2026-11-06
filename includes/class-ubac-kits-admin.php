<?php
/**
 * Admin: kit configuration metabox (components, scenarios, JSON
 * import/export).
 *
 * The interface (assets/js/ubac-kits-admin.js) builds a configuration
 * JSON into a hidden field; when the product is saved, that JSON is
 * normalised by Ubac_Kits_Kit_Config::save(). The same format drives
 * import/export — that is how comparison kits are created on staging (see
 * RESUME_TECHNIQUE.md).
 *
 * @package Ubac_Kits
 */

defined( 'ABSPATH' ) || exit;

class Ubac_Kits_Admin {

	const NONCE = 'ubac_kits_save_kit';

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
	 * Exception: the Appearance panel. Its settings are NOT part of the kit
	 * configuration (neither of the JSON nor of import / export): they are
	 * ordinary form fields, saved as separate metas (see
	 * Ubac_Kits_Appearance and save()).
	 *
	 * 'closed' => collapsed on the first visit only (see postbox_classes).
	 */
	const BOXES = array(
		'ubac-kit-components-box' => array( 'closed' => false ),
		'ubac-kit-scenarios-box'  => array( 'closed' => false ),
		'ubac-kit-warnings-box'   => array( 'closed' => false ),
	);

	/**
	 * Panels of the “Product data” block, in display order: key of the panel
	 * (also the id of its element, which the script mounts onto) => tab
	 * priority and render method.
	 *
	 * The four parts that do not need the width of the page live here, next to
	 * Inventory and Shipping; the three boxes above keep it. The ids are the
	 * same as when these were boxes, and so are the form fields: saving does
	 * not know the difference.
	 */
	const TABS = array(
		'ubac-kit-appearance-box' => array( 'priority' => 35, 'render' => 'render_appearance_box' ),
		'ubac-kit-simulator-box'  => array( 'priority' => 36, 'render' => 'render_simulator_box' ),
		'ubac-kit-io-box'         => array( 'priority' => 37, 'render' => 'render_io_box' ),
		'ubac-kit-exceptions-box' => array( 'priority' => 38, 'render' => 'render_exceptions_box' ),
	);

	/** Fields posted by the global settings shown in the Appearance panel. */
	const GLOBALS_MARKER = 'ubac_kits_global_appearance';

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
	 * (UbacKitsAdmin.i18n) through its L() helper, which falls back to an
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
			'moveUp'                    => __( 'Move up', 'ubac-composite-products' ),
			'moveDown'                  => __( 'Move down', 'ubac-composite-products' ),
			'duplicate'                 => __( 'Duplicate', 'ubac-composite-products' ),
			'delete'                    => __( 'Delete', 'ubac-composite-products' ),
			'change'                    => __( 'Change', 'ubac-composite-products' ),
			'remove'                    => __( 'Remove', 'ubac-composite-products' ),
			'choose'                    => __( 'Choose', 'ubac-composite-products' ),
			'image'                     => __( 'Image', 'ubac-composite-products' ),
			'media'                     => __( 'Media', 'ubac-composite-products' ),
			'labelField'                => __( 'Label', 'ubac-composite-products' ),
			'titleField'                => __( 'Title', 'ubac-composite-products' ),
			'badgeField'                => __( 'Badge', 'ubac-composite-products' ),
			'identifier'                => __( 'Identifier', 'ubac-composite-products' ),
			'identifierFromLabel'       => __( 'generated from the label', 'ubac-composite-products' ),
			'colourField'               => __( 'Colour', 'ubac-composite-products' ),
			'optionNone'                => __( '— None —', 'ubac-composite-products' ),
			'noLabel'                   => __( '(no label)', 'ubac-composite-products' ),
			'untitled'                  => __( '(untitled)', 'ubac-composite-products' ),
			'none'                      => __( 'None', 'ubac-composite-products' ),
			'yes'                       => __( 'yes', 'ubac-composite-products' ),
			'no'                        => __( 'no', 'ubac-composite-products' ),
			'copy'                      => __( 'Copy', 'ubac-composite-products' ),
			'copied'                    => __( 'Copied', 'ubac-composite-products' ),
			'copyFailed'                => __( 'Copy failed', 'ubac-composite-products' ),
			'dragToReorder'             => __( 'Drag to reorder', 'ubac-composite-products' ),
			'cannotBeUndone'            => __( 'This cannot be undone without reloading the page.', 'ubac-composite-products' ),
			/* translators: %s: HTTP status code returned by the request. */
			'httpError'                 => __( 'Error %s', 'ubac-composite-products' ),
			/* translators: %s: name of the item that could not be found. */
			'notFound'                  => __( '%s (not found)', 'ubac-composite-products' ),

			// Component box: type, choice mode, screen and quantity.
			'componentsBox'             => __( 'Components', 'ubac-composite-products' ),
			'componentType'             => __( 'Component type', 'ubac-composite-products' ),
			'typeProducts'              => __( 'Products', 'ubac-composite-products' ),
			'typeBranch'                => __( 'Branch (no product)', 'ubac-composite-products' ),
			'choiceField'               => __( 'Choice', 'ubac-composite-products' ),
			'choiceSingle'              => __( 'Single (one option)', 'ubac-composite-products' ),
			'choiceMultiple'            => __( 'Multiple (several options, one row each)', 'ubac-composite-products' ),
			'layoutField'               => __( 'Layout', 'ubac-composite-products' ),
			'styleDefault'              => __( 'Default (general appearance)', 'ubac-composite-products' ),
			'styleImage'                => __( 'With image — cards in a grid', 'ubac-composite-products' ),
			'styleRow'                  => __( 'Inline — one expandable row per option', 'ubac-composite-products' ),
			'styleSmall'                => __( 'Small — compact grid', 'ubac-composite-products' ),
			'styleIcon'                 => __( 'Category icon', 'ubac-composite-products' ),
			'styleChips'                => __( 'Tickable chips', 'ubac-composite-products' ),
			'displayGroup'              => __( 'Display group', 'ubac-composite-products' ),
			'displayGroupPlaceholder'   => __( 'same value = same screen', 'ubac-composite-products' ),
			'titleRoleField'            => __( 'Title on the screen', 'ubac-composite-products' ),
			'titleRolePrimary'          => __( 'Main (in the screen title)', 'ubac-composite-products' ),
			'titleRoleSecondary'        => __( 'Secondary (add-on, no title)', 'ubac-composite-products' ),
			'presentationField'         => __( 'Add-on presentation', 'ubac-composite-products' ),
			'presentationCard'          => __( '“Select” card', 'ubac-composite-products' ),
			'presentationQuestion'      => __( 'Yes / no question', 'ubac-composite-products' ),
			'questionField'             => __( 'Text of the question', 'ubac-composite-products' ),
			'questionPlaceholder'       => __( 'Empty: the title of the component', 'ubac-composite-products' ),
			'quantityEditable'          => __( 'Adjustable quantity', 'ubac-composite-products' ),
			'quantityMin'               => __( 'Min quantity', 'ubac-composite-products' ),
			'quantityMax'               => __( 'Max quantity', 'ubac-composite-products' ),
			'optionalField'             => __( 'Optional', 'ubac-composite-products' ),
			'shippedIndividually'       => __( 'Shipped on its own (otherwise its weight is added to the composite product parcel)', 'ubac-composite-products' ),
			'weightModeField'           => __( 'Displayed weight', 'ubac-composite-products' ),
			'weightModeProducts'        => __( 'Weight of the products', 'ubac-composite-products' ),
			'weightModeFixed'           => __( 'Fixed value', 'ubac-composite-products' ),
			'weightModeSubtract'        => __( 'Weight of the products minus…', 'ubac-composite-products' ),
			'weightGrams'               => __( 'Grams', 'ubac-composite-products' ),
			'descriptionField'          => __( 'Description (HTML allowed)', 'ubac-composite-products' ),
			'infoBubbleText'            => __( 'Info bubble text (optional)', 'ubac-composite-products' ),
			'optionsProductsField'      => __( 'Options (products)', 'ubac-composite-products' ),
			'searchProduct'             => __( 'Search for a product…', 'ubac-composite-products' ),
			'defaultSelectionField'     => __( 'Default selection', 'ubac-composite-products' ),
			'defaultOptionField'        => __( 'Default option', 'ubac-composite-products' ),

			// Component box: badges of the folded header.
			'badgeBranch'               => __( 'Branch', 'ubac-composite-products' ),
			'badgeProducts'             => __( 'Products', 'ubac-composite-products' ),
			'badgeMultiple'             => __( 'multiple choice', 'ubac-composite-products' ),
			'badgeOptional'             => __( 'optional', 'ubac-composite-products' ),
			/* translators: %s: name of the screen the component is shown on. */
			'badgeScreen'               => __( 'screen: %s', 'ubac-composite-products' ),
			/* translators: %s: number of options. */
			'optionCount'               => __( 'Options: %s', 'ubac-composite-products' ),
			/* translators: %s: number of ranges. */
			'rangeCount'                => __( 'Ranges: %s', 'ubac-composite-products' ),
			/* translators: %s: number of restricted options. */
			'restrictedCount'           => __( 'Restricted: %s', 'ubac-composite-products' ),
			'everythingPublic'          => __( 'everything is public', 'ubac-composite-products' ),

			// Anchoring an add-on to the card of another component.
			'anchorNextTo'              => __( 'Anchor next to', 'ubac-composite-products' ),
			'anchorAutomatic'           => __( '— Automatic (the chosen card of the first component on the screen) —', 'ubac-composite-products' ),
			/* translators: %s: name of the component, which sits on another screen. */
			'anchorOtherScreen'         => __( '%s (other screen)', 'ubac-composite-products' ),
			'anchorCard'                => __( 'Card', 'ubac-composite-products' ),
			'anchorChosenCard'          => __( '— Chosen card —', 'ubac-composite-products' ),
			/* translators: %s: name of the card that is no longer among the options. */
			'anchorAbsent'              => __( '%s (absent from the options)', 'ubac-composite-products' ),

			// Categories (ranges) of a component.
			/* translators: %s: number of categories. */
			'rangesPanel'               => __( 'Categories / ranges (%s)', 'ubac-composite-products' ),
			'rangeAdd'                  => __( 'Add a category', 'ubac-composite-products' ),
			'rangeCreate'               => __( 'Create a category', 'ubac-composite-products' ),
			'rangeField'                => __( 'Range', 'ubac-composite-products' ),
			'rangeNoCategory'           => __( 'Range: no category.', 'ubac-composite-products' ),
			'rangeDisplay'              => __( 'Display of the ranges:', 'ubac-composite-products' ),
			'rangeNewline'              => __( 'On its own line', 'ubac-composite-products' ),
			'rangeBadge'                => __( 'Badge', 'ubac-composite-products' ),
			'rangeTint'                 => __( 'Coloured background', 'ubac-composite-products' ),
			/* translators: %s: the default colour, in hexadecimal. */
			'colourDefault'             => __( 'Default (%s)', 'ubac-composite-products' ),
			'tierMedia'                 => __( 'Tooltip image or video (optional)', 'ubac-composite-products' ),
			'tierMediaUrl'              => __( 'or a video link (YouTube, Vimeo, .mp4 file)', 'ubac-composite-products' ),
			'chooseFromLibrary'         => __( 'Choose from the media library', 'ubac-composite-products' ),

			// Product option: display, variations, help image, visibility.
			'displayedTitle'            => __( 'Displayed title', 'ubac-composite-products' ),
			'badgePlaceholder'          => __( 'e.g. Best seller', 'ubac-composite-products' ),
			'displayedDescription'      => __( 'Displayed description (simple HTML)', 'ubac-composite-products' ),
			'showImage'                 => __( 'Show the image', 'ubac-composite-products' ),
			'showDescription'           => __( 'Description', 'ubac-composite-products' ),
			'showSpecs'                 => __( 'Specifications', 'ubac-composite-products' ),
			'showBundled'               => __( 'Included products', 'ubac-composite-products' ),
			'customiseDisplay'          => __( 'Customise the display', 'ubac-composite-products' ),
			'changeImage'               => __( 'Change the image', 'ubac-composite-products' ),
			'chooseImage'               => __( 'Choose an image', 'ubac-composite-products' ),
			'everyVariant'              => __( 'Every variant', 'ubac-composite-products' ),
			'helpImagePanel'            => __( 'Help image', 'ubac-composite-products' ),
			'variantsPanel'             => __( 'Variations offered', 'ubac-composite-products' ),
			'variantsAll'               => __( 'all', 'ubac-composite-products' ),
			/* translators: %s: the variants the restriction applies to. */
			'variantsRestrictedCount'   => __( 'restricted on %s', 'ubac-composite-products' ),
			'visibilityPanel'           => __( 'Public visibility', 'ubac-composite-products' ),
			'publicLabel'               => __( 'public', 'ubac-composite-products' ),
			'restricted'                => __( 'restricted', 'ubac-composite-products' ),
			'restrictedEveryVariant'    => __( 'restricted (every variant)', 'ubac-composite-products' ),
			'noneCard'                  => __( '“None” card', 'ubac-composite-products' ),
			'valueNoSelection'          => __( '— No selection —', 'ubac-composite-products' ),
			/* translators: %s: name of the value. */
			'valueEveryVariant'         => __( '%s — every variant', 'ubac-composite-products' ),
			/* translators: %s: name of the value. */
			'valueVariantPending'       => __( '%s — variant not chosen yet', 'ubac-composite-products' ),

			// Options of a branch component.
			'branchOptions'             => __( 'Options of the branch', 'ubac-composite-products' ),
			'addOption'                 => __( 'Add an option', 'ubac-composite-products' ),
			'deleteThisOption'          => __( 'Delete this option', 'ubac-composite-products' ),
			'badgePathPlaceholder'      => __( 'e.g. New', 'ubac-composite-products' ),
			'comingSoon'                => __( 'Coming soon', 'ubac-composite-products' ),
			'linkField'                 => __( 'Link (optional)', 'ubac-composite-products' ),
			'linkPlaceholder'           => __( 'page slug (contact) or https://…', 'ubac-composite-products' ),
			'linkTitle'                 => __( 'Filled in: the card is no longer a choice, it opens this page.', 'ubac-composite-products' ),

			// Media library frames (title and confirm button).
			'mediaOptionImage'          => __( 'Image of the option', 'ubac-composite-products' ),
			'mediaHelpImage'            => __( 'Help image (back of the card)', 'ubac-composite-products' ),
			/* translators: %s is the title of the component. */
			'mediaNoneImage'            => __( 'Image of the “None” card — %s', 'ubac-composite-products' ),
			/* translators: %s: name of the category the media belongs to. */
			'mediaTierTooltip'          => __( 'Tooltip image or video “%s”', 'ubac-composite-products' ),
			'mediaUseThisVisual'        => __( 'Use this visual', 'ubac-composite-products' ),
			'mediaUseThisImage'         => __( 'Use this image', 'ubac-composite-products' ),
			'mediaUseThisMedia'         => __( 'Use this media', 'ubac-composite-products' ),

			// Duplicating and deleting.
			/* translators: %s is the name of the item being duplicated. */
			'copySuffix'                => __( '%s (copy)', 'ubac-composite-products' ),
			/* translators: %s: name of the category about to be deleted. */
			'tierDeleteConfirm'         => __( 'Delete the category “%s”?', 'ubac-composite-products' ),
			'tierDeleteAttached'        => array(
				/* translators: %s: number of options attached to the category. */
				_n( '%s option is attached to it: it will go back to having no category.', '%s options are attached to it: they will go back to having no category.', 1, 'ubac-composite-products' ),
				/* translators: %s: number of options attached to the category. */
				_n( '%s option is attached to it: it will go back to having no category.', '%s options are attached to it: they will go back to having no category.', 2, 'ubac-composite-products' ),
			),
			'tierDeleteNoOption'        => __( 'No option is attached to it.', 'ubac-composite-products' ),
			/* translators: %s: name of the component about to be deleted. */
			'componentDeleteConfirm'    => __( 'Delete the component “%s”?', 'ubac-composite-products' ),
			'componentDeleteLinked'     => array(
				/* translators: %s: number of scenarios that refer to the component. */
				_n( '%s scenario refers to it and will be deleted, or have this condition removed.', '%s scenarios refer to it and will be deleted, or have this condition removed.', 1, 'ubac-composite-products' ),
				/* translators: %s: number of scenarios that refer to the component. */
				_n( '%s scenario refers to it and will be deleted, or have this condition removed.', '%s scenarios refer to it and will be deleted, or have this condition removed.', 2, 'ubac-composite-products' ),
			),
			/* translators: %s: name of the scenario about to be deleted. */
			'scenarioDeleteConfirm'     => __( 'Delete the scenario “%s”?', 'ubac-composite-products' ),

			// Import and export of the configuration JSON.
			/* translators: %s: the error reported by the JSON parser. */
			'importInvalidJson'         => __( 'Invalid JSON: %s', 'ubac-composite-products' ),
			'importNoComponents'        => __( 'Invalid JSON: the “components” key is missing.', 'ubac-composite-products' ),
			'importDone'                => __( 'Configuration imported: save the product to apply it.', 'ubac-composite-products' ),
			/* translators: %s: the list of things that were kept, built from the lines below. */
			'importKept'                => __( 'Already set in the admin and absent from the imported JSON, so kept: %s.', 'ubac-composite-products' ),
			/* translators: %s: number of images. */
			'keptImages'                => __( '%s image(s)', 'ubac-composite-products' ),
			/* translators: %s: number of help images. */
			'keptHelpImages'            => __( '%s help image(s)', 'ubac-composite-products' ),
			/* translators: %s: number of option titles. */
			'keptOptionTitles'          => __( '%s option title(s)', 'ubac-composite-products' ),
			/* translators: %s: number of option labels. */
			'keptOptionLabels'          => __( '%s option label(s)', 'ubac-composite-products' ),
			/* translators: %s: number of option descriptions. */
			'keptOptionDescriptions'    => __( '%s option description(s)', 'ubac-composite-products' ),
			/* translators: %s: number of badges. */
			'keptBadges'                => __( '%s badge(s)', 'ubac-composite-products' ),
			/* translators: %s: number of component titles. */
			'keptComponentTitles'       => __( '%s component title(s)', 'ubac-composite-products' ),
			/* translators: %s: number of component layouts. */
			'keptComponentLayouts'      => __( '%s component layout(s)', 'ubac-composite-products' ),
			/* translators: %s: number of component descriptions. */
			'keptComponentDescriptions' => __( '%s component description(s)', 'ubac-composite-products' ),
			/* translators: %s: number of component info bubble texts. */
			'keptComponentInfo'         => __( '%s component info bubble text(s)', 'ubac-composite-products' ),
			/* translators: %s: number of “None” card images. */
			'keptNoneImages'            => __( '%s “None” card image(s)', 'ubac-composite-products' ),
			/* translators: %s: number of range info bubble texts. */
			'keptRangeInfo'             => __( '%s range info bubble text(s)', 'ubac-composite-products' ),
			/* translators: %s: number of range media. */
			'keptRangeMedia'            => __( '%s range media', 'ubac-composite-products' ),
			/* translators: %s: number of range video links. */
			'keptRangeVideoLinks'       => __( '%s range video link(s)', 'ubac-composite-products' ),
			/* translators: %s: number of card links. */
			'keptCardLinks'             => __( '%s card link(s)', 'ubac-composite-products' ),
			/* translators: %s: number of offered variation choices. */
			'keptOfferedVariations'     => __( '%s offered variation choices', 'ubac-composite-products' ),
			/* translators: %s: number of variant help images. */
			'keptVariantHelpImages'     => __( '%s variant help images', 'ubac-composite-products' ),
			/* translators: %s: number of range colours. */
			'keptRangeColours'          => __( '%s range colour(s)', 'ubac-composite-products' ),
			/* translators: %s: number of “On its own line” settings. */
			'keptRangeNewline'          => __( '%s “On its own line” setting(s)', 'ubac-composite-products' ),
			/* translators: %s: number of “Badge” settings. */
			'keptRangeBadge'            => __( '%s “Badge” setting(s)', 'ubac-composite-products' ),
			/* translators: %s: number of “Coloured background” settings. */
			'keptRangeTint'             => __( '%s “Coloured background” setting(s)', 'ubac-composite-products' ),

			// Scenarios box: fields, actions and conditions.
			'scenariosBox'              => __( 'Scenarios', 'ubac-composite-products' ),
			'targetComponentField'      => __( 'Target component', 'ubac-composite-products' ),
			'targetComponentsField'     => __( 'Target components', 'ubac-composite-products' ),
			'actionField'               => __( 'Action', 'ubac-composite-products' ),
			'actionHideOptions'         => __( 'Hide options', 'ubac-composite-products' ),
			'actionShowOnly'            => __( 'Show only these options', 'ubac-composite-products' ),
			'actionHideStep'            => __( 'Hide the whole step', 'ubac-composite-products' ),
			'actionDeadEnd'             => __( 'Dead end (stop the configurator with a message)', 'ubac-composite-products' ),
			'blockMessageField'         => __( 'Message shown (dead end)', 'ubac-composite-products' ),
			'restartField'              => __( 'Resume at step', 'ubac-composite-products' ),
			/* translators: %s: name of the component whose options can be hidden. */
			'scenMaskedField'           => __( 'Options to hide in “%s”', 'ubac-composite-products' ),
			/* translators: %s: name of the component whose options can be kept visible. */
			'scenShownField'            => __( 'Options to keep visible in “%s”', 'ubac-composite-products' ),
			'condOn'                    => __( 'Condition on', 'ubac-composite-products' ),
			'condIsOneOf'               => __( 'is one of', 'ubac-composite-products' ),
			'condIsNoneOf'              => __( 'is none of', 'ubac-composite-products' ),
			'condNone'                  => __( 'No condition possible: always active.', 'ubac-composite-products' ),
			/* translators: %s is the customer condition, written out. */
			'condCustomerLine'          => __( 'Customer condition: %s (import JSON).', 'ubac-composite-products' ),
			'badgeDeadEnd'              => __( 'Dead end', 'ubac-composite-products' ),
			'badgeHidesStep'            => __( 'Hides the step', 'ubac-composite-products' ),
			'badgeShowsOnly'            => __( 'Shows only', 'ubac-composite-products' ),
			'badgeHidesOptions'         => __( 'Hides options', 'ubac-composite-products' ),
			'filterEveryTarget'         => __( 'Every target', 'ubac-composite-products' ),
			'targetMissing'             => __( '(target missing)', 'ubac-composite-products' ),

			// Scenarios box: readable sentence of a scenario.
			'scenDeadEnd'               => __( 'Dead end', 'ubac-composite-products' ),
			/* translators: %s: name of the step that is hidden. */
			'scenHidesStep'             => __( 'Hides the step %s', 'ubac-composite-products' ),
			/* translators: %s: names of the steps that are hidden. */
			'scenHidesSteps'            => __( 'Hides the steps %s', 'ubac-composite-products' ),
			/* translators: %s: name of the component whose options are hidden. */
			'scenHidesOptions'          => __( 'Hides options of %s', 'ubac-composite-products' ),
			/* translators: 1: name of the target component, 2: the list of options kept visible. */
			'scenShowsOnly'             => __( 'Shows in %1$s only %2$s', 'ubac-composite-products' ),
			'scenNoOptionChosen'        => __( '(no option chosen)', 'ubac-composite-products' ),
			/* translators: %s: name of the component that carries no value. */
			'scenNoValue'               => __( '%s (no value)', 'ubac-composite-products' ),
			/* translators: 1: what the scenario does, 2: the list of its conditions. */
			'scenIf'                    => __( '%1$s if %2$s', 'ubac-composite-products' ),
			/* translators: joins two conditions of a scenario; keep the surrounding spaces. */
			'scenAnd'                   => __( ' and ', 'ubac-composite-products' ),
			/* translators: %s: name of the scenario. */
			'scenAlwaysActive'          => __( '%s (always active)', 'ubac-composite-products' ),
			/* translators: %s: the customer number. */
			'customerNumber'            => __( 'customer #%s', 'ubac-composite-products' ),
			/* translators: %s: the list of customers the condition includes. */
			'customerIn'                => __( 'customer ∈ {%s}', 'ubac-composite-products' ),
			/* translators: %s: the list of customers the condition excludes. */
			'customerNotIn'             => __( 'customer ∉ {%s}', 'ubac-composite-products' ),

			// Customer exceptions and the rules the server generates from them.
			'exceptionsBox'             => __( 'Customer exceptions', 'ubac-composite-products' ),
			'excNone'                   => __( 'No exception.', 'ubac-composite-products' ),
			'customerField'             => __( 'Customer', 'ubac-composite-products' ),
			'searchCustomer'            => __( 'Search for a customer (name, e-mail)…', 'ubac-composite-products' ),
			'componentField'            => __( 'Component', 'ubac-composite-products' ),
			'noteField'                 => __( 'Note (internal)', 'ubac-composite-products' ),
			'notePlaceholder'           => __( 'e.g. loyal customer, agreement of 12/09', 'ubac-composite-products' ),
			'excAccessAll'              => __( 'Access to every restricted option and variation of this component', 'ubac-composite-products' ),
			'excAccessList'             => __( 'Only these, on top of the public ones:', 'ubac-composite-products' ),
			'excNothingRestricted'      => __( 'Nothing is restricted on this component.', 'ubac-composite-products' ),
			/* translators: 1: name of the customer, 2: name of the component. */
			'excSentenceAll'            => __( '%1$s — %2$s: every restricted option and variation', 'ubac-composite-products' ),
			/* translators: 1: name of the customer, 2: name of the component, 3: number of reserved values. */
			'excSentenceList'           => array(
				/* translators: 1: name of the component, 2: name of the value, 3: number of reserved values. */
				_n( '%1$s — %2$s: %3$s reserved value on top of the public ones', '%1$s — %2$s: %3$s reserved values on top of the public ones', 1, 'ubac-composite-products' ),
				/* translators: 1: name of the component, 2: name of the value, 3: number of reserved values. */
				_n( '%1$s — %2$s: %3$s reserved value on top of the public ones', '%1$s — %2$s: %3$s reserved values on top of the public ones', 2, 'ubac-composite-products' ),
			),
			'reservedValues'            => array(
				/* translators: %s: number of reserved values. */
				_n( '%s reserved value', '%s reserved values', 1, 'ubac-composite-products' ),
				/* translators: %s: number of reserved values. */
				_n( '%s reserved value', '%s reserved values', 2, 'ubac-composite-products' ),
			),
			'nothingRestricted'         => __( 'nothing restricted', 'ubac-composite-products' ),
			'everyVariantOfIt'          => __( 'every one of its variants', 'ubac-composite-products' ),
			'unknownComponent'          => __( '(component?)', 'ubac-composite-products' ),
			'customerToChoose'          => __( '(customer to be chosen)', 'ubac-composite-products' ),
			'generatedTitle'            => __( 'Generated rules (read only)', 'ubac-composite-products' ),
			'generatedNone'             => __( 'Generated rules: none (nothing is restricted).', 'ubac-composite-products' ),
			/* translators: 1: number of values hidden, 2: name of the component, 3: who the rule applies to, 4: the values. */
			'generatedLine'             => array(
				/* translators: 1: number of hidden values, 2: name of the component, 3: who the restriction applies to, 4: the hidden values themselves. */
				_n( ' — hides %1$s value of “%2$s” %3$s: %4$s', ' — hides %1$s values of “%2$s” %3$s: %4$s', 1, 'ubac-composite-products' ),
				/* translators: 1: number of hidden values, 2: name of the component, 3: who the restriction applies to, 4: the hidden values themselves. */
				_n( ' — hides %1$s value of “%2$s” %3$s: %4$s', ' — hides %1$s values of “%2$s” %3$s: %4$s', 2, 'ubac-composite-products' ),
			),
			'generatedForEveryone'      => __( 'for everyone', 'ubac-composite-products' ),
			/* translators: %s: who the generated journey is reserved for. */
			'generatedOnlyFor'          => __( 'for %s only', 'ubac-composite-products' ),
			/* translators: %s: who the generated journey is not offered to. */
			'generatedExceptFor'        => __( 'for everyone, except %s', 'ubac-composite-products' ),

			// Simulator: journey, totals and configuration serial.
			'simComputing'              => __( 'Computing…', 'ubac-composite-products' ),
			'simNoStepVisible'          => __( 'No step is visible with these choices.', 'ubac-composite-products' ),
			/* translators: 1: screen number, 2: title of the screen. */
			'simScreenTitle'            => __( 'Screen %1$s — %2$s', 'ubac-composite-products' ),
			'simSummaryStep'            => __( 'Summary', 'ubac-composite-products' ),
			/* translators: 1: number of steps, 2: the steps, joined by an arrow. */
			'simPath'                   => array(
				/* translators: 1: number of steps, 2: the steps themselves. */
				_n( 'Journey shown to the customer (%1$s step): %2$s', 'Journey shown to the customer (%1$s steps): %2$s', 1, 'ubac-composite-products' ),
				/* translators: 1: number of steps, 2: the steps themselves. */
				_n( 'Journey shown to the customer (%1$s step): %2$s', 'Journey shown to the customer (%1$s steps): %2$s', 2, 'ubac-composite-products' ),
			),
			'simDeadEnd'                => __( 'Dead end.', 'ubac-composite-products' ),
			/* translators: %s: name of the step the customer can resume at. */
			'simRestartAt'              => __( 'Resume offered at step: %s', 'ubac-composite-products' ),
			'simOptionsUnavailable'     => __( 'Options unavailable (component renamed?).', 'ubac-composite-products' ),
			'simNoOptionAvailable'      => __( 'No option available with these choices.', 'ubac-composite-products' ),
			/* translators: %s: name of the parent product. */
			'simVariantOf'              => __( '— Variant of %s —', 'ubac-composite-products' ),
			'simChooseVariant'          => __( '— Choose a variant —', 'ubac-composite-products' ),
			'simStepToFill'             => __( 'Step to fill in.', 'ubac-composite-products' ),
			'simTotal'                  => __( 'Total:', 'ubac-composite-products' ),
			/* translators: 1: weight, 2: unit of weight. */
			'simWeight'                 => __( 'Estimated weight: %1$s %2$s', 'ubac-composite-products' ),
			'simValid'                  => __( 'Configuration complete and valid', 'ubac-composite-products' ),
			'simIncomplete'             => __( 'Configuration incomplete', 'ubac-composite-products' ),
			'simReset'                  => __( 'Reset', 'ubac-composite-products' ),
			'simSerialLabel'            => __( 'Configuration serial:', 'ubac-composite-products' ),
			'simSerialPartialLabel'     => __( 'Configuration serial (partial configuration):', 'ubac-composite-products' ),
			'simSerialNone'             => __( 'no serial as long as no choice is made.', 'ubac-composite-products' ),
			'simOpenLink'               => __( 'Open on the site', 'ubac-composite-products' ),
			'simOpenTitle'              => __( 'Reopens the configurator on these choices', 'ubac-composite-products' ),
			'simViewVisitor'            => __( 'Visitor / customer with no exception', 'ubac-composite-products' ),
			'simViewAs'                 => __( 'View as:', 'ubac-composite-products' ),
			'simViewAsHelp'             => __( '(this customer’s hidden options; prices of the logged-in account)', 'ubac-composite-products' ),
			'warningsBox'               => __( 'Warnings', 'ubac-composite-products' ),
			'warningsTitle'             => __( 'Worth checking', 'ubac-composite-products' ),

			// Simulator: sentence describing the appearance in force.
			/* translators: 1: card style, 2: corners, 3: icons, 4: info on desktop, 5: info on mobile, 6: click. */
			'appearanceSentence'        => __( 'Appearance on the product page: %1$s, corners %2$s, icons %3$s, info %4$s (desktop) / %5$s (mobile), click %6$s.', 'ubac-composite-products' ),
			'appearanceCard'            => __( 'Card', 'ubac-composite-products' ),
			'appearanceThumbnail'       => __( 'Thumbnail', 'ubac-composite-products' ),
			'appearanceRounded'         => __( 'rounded', 'ubac-composite-products' ),
			'appearanceSquare'          => __( 'square', 'ubac-composite-products' ),
			'appearanceInfoSide'        => __( 'side', 'ubac-composite-products' ),
			'appearanceInfoCard'        => __( 'card', 'ubac-composite-products' ),
			'appearanceInfoExtended'    => __( 'extended', 'ubac-composite-products' ),
		);
	}

	private static function box_titles() {
		return array(
			'ubac-kit-appearance-box' => __( 'Appearance', 'ubac-composite-products' ),
			'ubac-kit-components-box' => __( 'Components', 'ubac-composite-products' ),
			'ubac-kit-scenarios-box'  => __( 'Scenarios', 'ubac-composite-products' ),
			'ubac-kit-exceptions-box' => __( 'Customer exceptions', 'ubac-composite-products' ),
			'ubac-kit-warnings-box'   => __( 'Warnings', 'ubac-composite-products' ),
			'ubac-kit-simulator-box'  => __( 'Simulator', 'ubac-composite-products' ),
			'ubac-kit-io-box'         => __( 'Import / export', 'ubac-composite-products' ),
		);
	}

	public static function init() {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_meta_box' ) );
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_data_tabs' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'product_data_panels' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		// Nonce and save field: outside the boxes (see the method).
		add_action( 'edit_form_after_editor', array( __CLASS__, 'render_save_field' ) );
		add_action( 'wp_ajax_ubac_kits_product_info', array( __CLASS__, 'ajax_product_info' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function add_meta_box() {
		$callbacks = array(
			'ubac-kit-components-box' => 'render_components_box',
			'ubac-kit-scenarios-box'  => 'render_scenarios_box',
			'ubac-kit-warnings-box'   => 'render_warnings_box',
		);

		$titles = self::box_titles();

		foreach ( array_keys( self::BOXES ) as $id ) {
			add_meta_box(
				$id,
				$titles[ $id ],
				array( __CLASS__, $callbacks[ $id ] ),
				'product',
				'normal',
				'high'
			);

			// Shared class: the script hides the boxes at once
			// when the product is not a composite product.
			add_filter(
				'postbox_classes_product_' . $id,
				function ( $classes ) use ( $id ) {
					$classes[] = 'ubac-kit-postbox';
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
	 * Tabs of the “Product data” block, shown for this product type only
	 * (the same show_if_ class as Inventory and Shipping, see
	 * Ubac_Kits_Product_Type::product_data_tabs()).
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function product_data_tabs( $tabs ) {
		$titles = self::box_titles();
		foreach ( self::TABS as $id => $tab ) {
			$tabs[ $id ] = array(
				'label'    => $titles[ $id ],
				'target'   => $id,
				'class'    => array( 'show_if_' . UBAC_KITS_PRODUCT_TYPE, 'ubac-kit-tab', 'ubac_kit_tab_' . str_replace( '-', '_', $id ) ),
				'priority' => $tab['priority'],
			);
		}
		return $tabs;
	}

	/**
	 * Panels of those tabs: one element per panel, with the id the script
	 * mounts onto, inside the form of the product.
	 */
	public static function product_data_panels() {
		foreach ( self::TABS as $id => $tab ) {
			// `ubac-ui` on the panel itself: the family sheet is scoped, and a metabox
			// lives outside any page wrapper of ours. Without it the buttons inside
			// would carry our class names and none of our styles.
			echo '<div id="' . esc_attr( $id ) . '" class="ubac-ui panel woocommerce_options_panel hidden ubac-kit-tab-panel">';
			call_user_func( array( __CLASS__, $tab['render'] ) );
			echo '</div>';
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
		/*
		 * The family sheet, first and as a dependency of ours.
		 *
		 * It was not queued here at all, and nothing else queued it on this
		 * screen: the four product-data panels carry `ubac-ui` and five buttons
		 * carry `ubac-ui-btn`, and `ubac-ui.css` is scoped under `.ubac-ui`
		 * throughout — so the whole kit editor wore the family's class names
		 * and none of its styles. Static checks cannot see that: the classes
		 * ARE defined in a sheet, the sheet IS queued somewhere in the plugin.
		 * It is only wrong on the screen that uses them.
		 */
		wp_enqueue_style( 'ubac-ui', UBAC_KITS_URL . 'assets/css/ubac-ui.css', array(), UBAC_KITS_VERSION );
		wp_enqueue_style( 'ubac-kits-admin', UBAC_KITS_URL . 'assets/css/ubac-kits-admin.css', array( 'ubac-ui' ), UBAC_KITS_VERSION );
		// “None” card image picker, now in the Appearance panel.
		wp_enqueue_style( 'ubac-kits-settings', UBAC_KITS_URL . 'assets/css/ubac-kits-settings.css', array( 'ubac-ui' ), UBAC_KITS_VERSION );
		wp_enqueue_script( 'ubac-kits-settings', UBAC_KITS_URL . 'assets/js/ubac-kits-settings.js', array( 'jquery', 'media-editor' ), UBAC_KITS_VERSION, true );
		// Simulator category sections: same module as the front end.
		wp_register_script( 'ubac-kits-tiers', UBAC_KITS_URL . 'assets/js/ubac-kits-tiers.js', array(), UBAC_KITS_VERSION, true );
		wp_enqueue_script( 'ubac-kits-admin', UBAC_KITS_URL . 'assets/js/ubac-kits-admin.js', array( 'jquery', 'wc-enhanced-select', 'ubac-kits-tiers' ), UBAC_KITS_VERSION, true );

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
			'components'          => Ubac_Kits_Kit_Config::get_components( $post_id ),
			'scenarios'           => Ubac_Kits_Kit_Config::get_scenarios( $post_id ),
			'customer_exceptions' => Ubac_Kits_Kit_Config::get_customer_exceptions( $post_id ),
		);

		// Customer labels of the exceptions; the ones chosen afterwards
		// come in through the WooCommerce customer search.
		$customers = array();
		foreach ( $config['customer_exceptions'] as $exception ) {
			$customers[ (int) $exception['customer_id'] ] = Ubac_Kits_Customer_Access::customer_label( $exception['customer_id'] );
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
			'ubac-kits-admin',
			'UbacKitsAdmin',
			array(
				'config'      => $config,
				'i18n'        => self::script_i18n(),
				'productInfo' => (object) self::product_info( $ids ),
				'mediaUrls'   => (object) $media_urls,
				'mediaNames'  => (object) $media_names,
				'productType' => UBAC_KITS_PRODUCT_TYPE,
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'infoNonce'   => wp_create_nonce( 'ubac_kits_product_info' ),
				'searchNonce' => wp_create_nonce( 'search-products' ),
				'customers'   => (object) $customers,
				'export'      => Ubac_Kits_Kit_Config::export( $post_id ),
				// Simulator: same routes as the front end, in
				// "configure online" mode. The wp_rest nonce is what
				// authenticates the call; the capability is checked server side.
				'restUrl'     => esc_url_raw( rest_url( Ubac_Kits_Rest::NAMESPACE_V1 . '/' ) ),
				'restNonce'   => wp_create_nonce( 'wp_rest' ),
				'productId'   => (int) $post_id,
				// Configuration serial: the simulator's "Open on the
				// site" link, published kits only.
				'kitUrl'      => 'publish' === get_post_status( $post_id ) ? get_permalink( $post_id ) : '',
				'serialParam' => Ubac_Kits_Composition::QUERY_ARG,
				// Saved appearance (the simulator displays it, then follows
				// the Appearance box buttons before saving).
				'appearance'  => Ubac_Kits_Appearance::get( $post_id ),
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
		echo '<textarea name="ubac_kit_config_json" id="ubac-kit-config-json" hidden></textarea>';
	}

	/**
	 * Configurator appearance for THIS product (Ubac_Kits_Appearance).
	 * Ordinary form fields, outside the configuration JSON, laid out as an
	 * ordinary WordPress settings table: one row per setting.
	 *
	 * The second table holds three GLOBAL options (they apply to every
	 * composite product), under a heading that says so: their controls live
	 * here, their values stay where they were (see save_global_appearance()).
	 */
	public static function render_appearance_box() {
		$appearance = Ubac_Kits_Appearance::get( (int) get_the_ID() );
		$choices    = array(
			'card_style'   => array(
				'label'   => __( 'Card style', 'ubac-composite-products' ),
				'options' => array(
					'vignette' => __( 'Thumbnail', 'ubac-composite-products' ),
					'card'     => __( 'Card', 'ubac-composite-products' ),
					'grid'     => __( 'Grid', 'ubac-composite-products' ),
				),
			),
			'card_corners' => array(
				'label'   => __( 'Corner shape', 'ubac-composite-products' ),
				'options' => array(
					'square'  => __( 'Square', 'ubac-composite-products' ),
					'rounded' => __( 'Rounded', 'ubac-composite-products' ),
				),
			),
			'icons'        => array(
				'label'   => __( 'Icons', 'ubac-composite-products' ),
				'options' => array(
					'on'  => __( 'Yes', 'ubac-composite-products' ),
					'off' => __( 'No', 'ubac-composite-products' ),
				),
			),
			'info_desktop' => array(
				'label'   => __( 'Info display (desktop)', 'ubac-composite-products' ),
				'options' => array(
					'card'     => __( 'Card', 'ubac-composite-products' ),
					'lateral'  => __( 'Side', 'ubac-composite-products' ),
					'extended' => __( 'Expanded', 'ubac-composite-products' ),
				),
			),
			'info_mobile'  => array(
				'label'   => __( 'Info display (mobile)', 'ubac-composite-products' ),
				'options' => array(
					'lateral'  => __( 'Side', 'ubac-composite-products' ),
					'card'     => __( 'Card', 'ubac-composite-products' ),
					'extended' => __( 'Expanded', 'ubac-composite-products' ),
				),
			),
			'click'        => array(
				'label'   => __( '“Select” button', 'ubac-composite-products' ),
				'options' => array(
					'on'  => __( 'Yes', 'ubac-composite-products' ),
					'off' => __( 'No', 'ubac-composite-products' ),
				),
			),
			'recap_bg'     => array(
				'label'   => __( 'Summary background', 'ubac-composite-products' ),
				'options' => array(
					'light' => __( 'Light', 'ubac-composite-products' ),
					'dark'  => __( 'Dark', 'ubac-composite-products' ),
				),
			),
		);
		$none_image_id = absint( get_option( Ubac_Kits_Settings::OPTION_NONE_IMAGE, 0 ) );
		$none_preview  = $none_image_id ? wp_get_attachment_image_url( $none_image_id, 'thumbnail' ) : '';
		?>
		<div class="ubac-kit-admin ubac-kit-admin__appearance">
			<table class="form-table" role="presentation">
				<?php foreach ( $choices as $key => $choice ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $choice['label'] ); ?></th>
						<td>
							<?php foreach ( $choice['options'] as $value => $label ) : ?>
								<label class="ubac-kit-admin__appearance-choice">
									<input type="radio" name="ubac_kit_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $appearance[ $key ], $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<h4 class="ubac-kit-admin__section-title"><?php esc_html_e( 'Settings shared by every composite product', 'ubac-composite-products' ); ?></h4>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Show more info', 'ubac-composite-products' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( self::GLOBALS_MARKER ); ?>" value="1">
						<label class="ubac-kit-admin__appearance-choice">
							<input type="checkbox" name="<?php echo esc_attr( Ubac_Kits_Settings::OPTION_MORE_INFO ); ?>" value="yes" <?php checked( Ubac_Kits_Settings::more_info_enabled() ); ?>>
							<?php esc_html_e( 'Yes', 'ubac-composite-products' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '“None” card image', 'ubac-composite-products' ); ?></th>
					<td>
						<input type="hidden" id="ubac-kits-none-image" name="<?php echo esc_attr( Ubac_Kits_Settings::OPTION_NONE_IMAGE ); ?>" value="<?php echo esc_attr( $none_image_id ? (string) $none_image_id : '' ); ?>">
						<?php
						/*
						 * NO `src` ATTRIBUTE AT ALL when there is no preview,
						 * rather than an empty one.
						 *
						 * `src=""` is not "no image": the browser resolves the
						 * empty string against the current document and fetches
						 * THE PAGE ITSELF as an image. `hidden` keeps it out of
						 * sight, so nothing looks wrong, and the request happens
						 * anyway on every load of this screen.
						 *
						 * Measured on 2026-10-31: this is what made the
						 * screenshot bench report a broken image on the product
						 * editor -- an <img> whose naturalWidth is 0 and whose
						 * src is `post.php?post=NN&action=edit`. The matching
						 * fix is in assets/js/ubac-kits-settings.js, which
						 * removes the attribute instead of blanking it.
						 *
						 * AND THE TAG STAYS ON ONE LINE. Written across several
						 * lines for readability, it broke two assertions of
						 * t-composite.php: the bench strips tags LINE BY LINE,
						 * so a tag spanning lines leaks its attributes into what
						 * the reader counts as screen text -- and
						 * `ubac-kit-settings__preview` contains the word "kit",
						 * which one of those assertions forbids on this screen.
						 * The product should not be shaped by a reader's limit,
						 * so the limit is written up as its own finding; but a
						 * one-line tag is the ordinary form here anyway.
						 */
						?>
						<img id="ubac-kits-none-preview" class="ubac-kit-settings__preview" alt=""<?php echo $none_preview ? ' src="' . esc_url( $none_preview ) . '"' : ' hidden'; ?>>
						<button type="button" class="ubac-ui-btn" id="ubac-kits-none-pick" data-frame-title="<?php esc_attr_e( 'Image for the “None” card', 'ubac-composite-products' ); ?>" data-frame-button="<?php esc_attr_e( 'Use this image', 'ubac-composite-products' ); ?>"><?php esc_html_e( 'Choose an image', 'ubac-composite-products' ); ?></button>
						<button type="button" class="button-link delete" id="ubac-kits-none-clear" <?php echo $none_image_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove the image', 'ubac-composite-products' ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ubac-kits-none-text"><?php esc_html_e( '“None” card text', 'ubac-composite-products' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="ubac-kits-none-text" name="<?php echo esc_attr( Ubac_Kits_Settings::OPTION_NONE_TEXT ); ?>" value="<?php echo esc_attr( (string) get_option( Ubac_Kits_Settings::OPTION_NONE_TEXT, '' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. I do not need one', 'ubac-composite-products' ); ?>">
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * The three GLOBAL settings shown in the Appearance panel (“Show more
	 * info”, “None” card image and text). Same options, same sanitising as
	 * on the settings screen they came from. Only when the panel was really
	 * posted (marker), only for someone allowed to change settings, so a
	 * product saved from anywhere else leaves them alone.
	 */
	private static function save_global_appearance() {
		/* THE NONCE IS CHECKED BY save(), WHICH CALLS THIS METHOD.
		 * wp_verify_nonce() returns there before reaching here; the sniff does
		 * not look beyond the function and so reports an option write "without
		 * nonce verification", which reads as a CSRF hole when the guard
		 * exists. */
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in save().
		if ( ! isset( $_POST[ self::GLOBALS_MARKER ] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		update_option( Ubac_Kits_Settings::OPTION_MORE_INFO, isset( $_POST[ Ubac_Kits_Settings::OPTION_MORE_INFO ] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST[ Ubac_Kits_Settings::OPTION_MORE_INFO ] ) ) ? 'yes' : '' );
		update_option( Ubac_Kits_Settings::OPTION_NONE_IMAGE, isset( $_POST[ Ubac_Kits_Settings::OPTION_NONE_IMAGE ] ) ? absint( wp_unslash( $_POST[ Ubac_Kits_Settings::OPTION_NONE_IMAGE ] ) ) : 0 );
		update_option( Ubac_Kits_Settings::OPTION_NONE_TEXT, isset( $_POST[ Ubac_Kits_Settings::OPTION_NONE_TEXT ] ) ? sanitize_text_field( wp_unslash( $_POST[ Ubac_Kits_Settings::OPTION_NONE_TEXT ] ) ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	public static function render_components_box() {
		?>
		<div class="ubac-ui ubac-kit-admin">
			<?php self::render_fold_bar( 'components' ); ?>
			<div id="ubac-kit-components"></div>
			<p><button type="button" class="ubac-ui-btn" id="ubac-kit-add-component"><?php esc_html_e( 'Add a component', 'ubac-composite-products' ); ?></button></p>
		</div>
		<?php
	}

	public static function render_scenarios_box() {
		?>
		<div class="ubac-ui ubac-kit-admin">
			<div class="ubac-kit-admin__filters">
				<label class="ubac-kit-admin__filter">
					<span class="screen-reader-text"><?php esc_html_e( 'Search scenarios', 'ubac-composite-products' ); ?></span>
					<input type="search" id="ubac-kit-scenario-search" class="regular-text"
						placeholder="<?php esc_attr_e( 'Search…', 'ubac-composite-products' ); ?>">
				</label>
				<label class="ubac-kit-admin__filter">
					<span class="screen-reader-text"><?php esc_html_e( 'Filter by target component', 'ubac-composite-products' ); ?></span>
					<select id="ubac-kit-scenario-target"><option value=""><?php esc_html_e( 'All targets', 'ubac-composite-products' ); ?></option></select>
				</label>
				<span class="ubac-kit-admin__filter-count" id="ubac-kit-scenario-count"></span>
			</div>
			<?php self::render_fold_bar( 'scenarios' ); ?>
			<div id="ubac-kit-scenarios"></div>
			<p><button type="button" class="ubac-ui-btn" id="ubac-kit-add-scenario"><?php esc_html_e( 'Add a scenario', 'ubac-composite-products' ); ?></button></p>
		</div>
		<?php
	}

	/**
	 * Customer exceptions: see Ubac_Kits_Customer_Access.
	 */
	public static function render_exceptions_box() {
		?>
		<div class="ubac-kit-admin">
			<div id="ubac-kit-exceptions"></div>
			<p><button type="button" class="ubac-ui-btn" id="ubac-kit-add-exception"><?php esc_html_e( 'Add an exception', 'ubac-composite-products' ); ?></button></p>
			<div id="ubac-kit-generated" class="ubac-kit-admin__generated"></div>
		</div>
		<?php
	}

	public static function render_warnings_box() {
		?>
		<div class="ubac-kit-admin">
			<div id="ubac-kit-warnings" class="ubac-kit-admin__warnings" hidden></div>
		</div>
		<?php
	}

	public static function render_simulator_box() {
		?>
		<div class="ubac-kit-admin">
			<div id="ubac-kit-simulator"></div>
		</div>
		<?php
	}

	public static function render_io_box() {
		?>
		<div class="ubac-kit-admin">
			<textarea id="ubac-kit-import" rows="8" class="large-text code"></textarea>
			<p>
				<button type="button" class="ubac-ui-btn" id="ubac-kit-import-button"><?php esc_html_e( 'Import', 'ubac-composite-products' ); ?></button>
				<button type="button" class="ubac-ui-btn" id="ubac-kit-export-button"><?php esc_html_e( 'Show export', 'ubac-composite-products' ); ?></button>
				<?php // Downloaded export: same JSON as "Show export", as a file. ?>
				<button type="button" class="ubac-ui-btn" id="ubac-kit-download-button"><?php esc_html_e( 'Export', 'ubac-composite-products' ); ?></button>
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
		<p class="ubac-kit-admin__foldbar">
			<button type="button" class="button-link" data-fold-all="<?php echo esc_attr( $section ); ?>"><?php esc_html_e( 'Collapse all', 'ubac-composite-products' ); ?></button>
			<button type="button" class="button-link" data-unfold-all="<?php echo esc_attr( $section ); ?>"><?php esc_html_e( 'Expand all', 'ubac-composite-products' ); ?></button>
		</p>
		<?php
	}

	/**
	 * @param WC_Product $product Product.
	 */
	public static function save( $product ) {
		if ( ! $product instanceof WC_Product_Ubac_Kit ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		// Appearance: BEFORE the configuration, which may stop further down
		// (JSON absent or unreadable) — the two saves are
		// independent. Separate metas, never touched by an import.
		$appearance = array();
		foreach ( array_keys( Ubac_Kits_Appearance::SETTINGS ) as $key ) {
			if ( isset( $_POST[ 'ubac_kit_' . $key ] ) ) {
				$appearance[ $key ] = sanitize_key( wp_unslash( $_POST[ 'ubac_kit_' . $key ] ) );
			}
		}
		Ubac_Kits_Appearance::save( $product->get_id(), $appearance );
		self::save_global_appearance();

		if ( ! isset( $_POST['ubac_kit_config_json'] ) || '' === $_POST['ubac_kit_config_json'] ) {
			return;
		}

		// JSON decoded, then fully normalised by Ubac_Kits_Kit_Config.
		$config = json_decode( wp_unslash( $_POST['ubac_kit_config_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $config ) ) {
			self::store_notice( array( __( 'The configuration could not be read: nothing was saved.', 'ubac-composite-products' ) ) );
			return;
		}

		$raw_components = isset( $config['components'] ) && is_array( $config['components'] ) ? $config['components'] : array();
		$raw_scenarios  = isset( $config['scenarios'] ) && is_array( $config['scenarios'] ) ? $config['scenarios'] : array();
		// Customer exceptions: key absent = unchanged.
		$raw_exceptions = isset( $config['customer_exceptions'] ) && is_array( $config['customer_exceptions'] ) ? $config['customer_exceptions'] : null;

		$errors = Ubac_Kits_Kit_Config::save( $product->get_id(), $raw_components, $raw_scenarios, $raw_exceptions );

		// Safety nets: never blocking, the configuration is saved whatever
		// happens. They only point out the places where the input does not
		// mean what one thinks (dead reference, cascade, dead end).
		$checked = Ubac_Kits_Validator::check( $raw_components, $raw_scenarios, $raw_exceptions );
		// check() replays the normalisation: its messages overlap those of
		// save(), hence the deduplication.
		$errors  = array_values( array_unique( array_merge( $errors, $checked['warnings'] ) ) );

		// Only a class that was named and cannot be found is worth a word. No
		// class named at all is a deliberate setting, not a mistake.
		if ( '' !== Ubac_Kits_Product_Type::get_container_shipping_class_slug() && ! Ubac_Kits_Product_Type::get_container_shipping_class_id() ) {
			$errors[] = __( 'The shipping class set for composite products (Ubac Composite settings) does not exist: shipping plugins will not recognise this product.', 'ubac-composite-products' );
		}

		if ( $errors ) {
			self::store_notice( $errors );
		}
	}

	/**
	 * @param string[] $messages Messages.
	 */
	private static function store_notice( array $messages ) {
		set_transient( 'ubac_kits_notice_' . get_current_user_id(), $messages, 120 );
	}

	public static function notices() {
		$key      = 'ubac_kits_notice_' . get_current_user_id();
		$messages = get_transient( $key );
		if ( ! $messages ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'Ubac Composite', 'ubac-composite-products' ) . '</strong></p><ul>';
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
		check_ajax_referer( 'ubac_kits_product_info', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( null, 403 );
		}
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		wp_send_json_success( (object) self::product_info( array_slice( $ids, 0, 200 ) ) );
	}
}
