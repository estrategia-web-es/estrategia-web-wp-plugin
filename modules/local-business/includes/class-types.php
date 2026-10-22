<?php
/**
 * THE TYPES OF BUSINESS a site (or one of its locations) can say it is: a
 * closed list of schema.org LocalBusiness subtypes, chosen in «Company
 * details › Type of business» and, per location, in the location's
 * «Type of business (schema.org)» field.
 *
 * Why a closed list and not free text: Google only understands schema.org
 * names, spelled exactly («DryCleaningOrLaundry», not «Laundry»), and a typo
 * would publish a type nobody reads. The list is what small and medium
 * businesses in Spain are; schema.org has more.
 *
 * Why the names are shown as they are (not translated): they are the
 * identifiers Google documents and the person choosing is whoever builds the
 * site. Translating 68 names into three languages would be a second list to
 * keep in step for no reader.
 *
 * How to maintain:
 * - Who and when: whoever needs a type that is not here. It goes in ALL
 *   (alphabetical after LocalBusiness), in the options of
 *   modules/content-types/templates/location.json (same order) and nowhere
 *   else; tests/graph.test.mjs (level 1) fails if the two lists differ.
 * - Criterion: a SUBTYPE of LocalBusiness on schema.org (check its page:
 *   «More specific Types» of LocalBusiness or of one of its subtypes).
 *   Boundary case, with a name: VeterinaryCare is a MedicalOrganization,
 *   NOT a LocalBusiness, so it does not go in; a vet chooses LocalBusiness
 *   (MedicalBusiness is human health).
 * - The trap: removing a type breaks the REST schema of the option on the
 *   sites that chose it (the settings controller returns null for a value
 *   outside the enum). Only add; removing is a major version.
 * - is_local() is also what tells that an SEO plugin's node is ALREADY a
 *   local business (Graph::merge()). A host type not in this list is not
 *   recognised and ours is added next to it: add the type here.
 */

namespace EstrategiaWeb\LocalBusiness;

defined( 'ABSPATH' ) || exit;

final class Types {

	public const DEFAULT = 'LocalBusiness';

	/** LocalBusiness first, then alphabetical. The location template's select repeats it. */
	public const ALL = array(
		'LocalBusiness',
		'AccountingService',
		'Attorney',
		'AutoRepair',
		'AutomotiveBusiness',
		'Bakery',
		'BarOrPub',
		'BeautySalon',
		'BedAndBreakfast',
		'BikeStore',
		'BookStore',
		'Brewery',
		'CafeOrCoffeeShop',
		'Campground',
		'ChildCare',
		'ClothingStore',
		'DaySpa',
		'Dentist',
		'Distillery',
		'DryCleaningOrLaundry',
		'Electrician',
		'ElectronicsStore',
		'EmploymentAgency',
		'EntertainmentBusiness',
		'ExerciseGym',
		'FinancialService',
		'Florist',
		'FoodEstablishment',
		'FurnitureStore',
		'GardenStore',
		'GroceryStore',
		'HairSalon',
		'HardwareStore',
		'HealthAndBeautyBusiness',
		'HomeAndConstructionBusiness',
		'Hostel',
		'Hotel',
		'IceCreamShop',
		'InsuranceAgency',
		'JewelryStore',
		'LegalService',
		'Locksmith',
		'LodgingBusiness',
		'MedicalBusiness',
		'MedicalClinic',
		'MovingCompany',
		'NailSalon',
		'Notary',
		'Optician',
		'PetStore',
		'Pharmacy',
		'Physiotherapy',
		'Plumber',
		'ProfessionalService',
		'RealEstateAgent',
		'RecyclingCenter',
		'Restaurant',
		'SelfStorage',
		'ShoeStore',
		'ShoppingCenter',
		'SportingGoodsStore',
		'SportsActivityLocation',
		'SportsClub',
		'Store',
		'TouristInformationCenter',
		'ToyStore',
		'TravelAgency',
		'Winery',
	);

	/** A valid type, or null. */
	public static function valid( $type ): ?string {
		return is_string( $type ) && in_array( $type, self::ALL, true ) ? $type : null;
	}

	/** Whether a node's @type (a string or a list) is a local business. */
	public static function is_local( $type ): bool {
		foreach ( (array) $type as $one ) {
			if ( is_string( $one ) && in_array( $one, self::ALL, true ) ) {
				return true;
			}
		}
		return false;
	}
}
