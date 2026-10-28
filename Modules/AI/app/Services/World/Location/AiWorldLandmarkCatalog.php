<?php

namespace Modules\AI\Services\World\Location;

/**
 * Built-in catalog of well-known landmarks so the location search works with no maps key and so the most requested
 * places are classified "reference based". Coordinates are approximate (about 100 m), enough to place a scene, not to survey.
 * Row: [slug, English name, Arabic name, country code, city, lat, lng].
 */
class AiWorldLandmarkCatalog
{
    public const LANDMARKS = [
        ['eiffel-tower', 'Eiffel Tower', 'برج إيفل', 'FR', 'Paris', 48.8584, 2.2945],
        ['louvre-museum', 'Louvre Museum', 'متحف اللوفر', 'FR', 'Paris', 48.8606, 2.3376],
        ['arc-de-triomphe', 'Arc de Triomphe', 'قوس النصر', 'FR', 'Paris', 48.8738, 2.2950],
        ['big-ben', 'Big Ben', 'ساعة بيج بن', 'GB', 'London', 51.5007, -0.1246],
        ['tower-bridge', 'Tower Bridge', 'جسر البرج', 'GB', 'London', 51.5055, -0.0754],
        ['colosseum', 'Colosseum', 'الكولوسيوم', 'IT', 'Rome', 41.8902, 12.4922],
        ['trevi-fountain', 'Trevi Fountain', 'نافورة تريفي', 'IT', 'Rome', 41.9009, 12.4833],
        ['leaning-tower-of-pisa', 'Leaning Tower of Pisa', 'برج بيزا المائل', 'IT', 'Pisa', 43.7230, 10.3966],
        ['sagrada-familia', 'Sagrada Familia', 'ساغرادا فاميليا', 'ES', 'Barcelona', 41.4036, 2.1744],
        ['brandenburg-gate', 'Brandenburg Gate', 'بوابة براندنبورغ', 'DE', 'Berlin', 52.5163, 13.3777],
        ['acropolis-of-athens', 'Acropolis of Athens', 'أكروبوليس أثينا', 'GR', 'Athens', 37.9715, 23.7257],
        ['oia-santorini', 'Oia, Santorini', 'أويا سانتوريني', 'GR', 'Santorini', 36.4618, 25.3753],
        ['red-square', 'Red Square', 'الساحة الحمراء', 'RU', 'Moscow', 55.7539, 37.6208],
        ['times-square', 'Times Square', 'تايمز سكوير', 'US', 'New York', 40.7580, -73.9855],
        ['statue-of-liberty', 'Statue of Liberty', 'تمثال الحرية', 'US', 'New York', 40.6892, -74.0445],
        ['golden-gate-bridge', 'Golden Gate Bridge', 'جسر البوابة الذهبية', 'US', 'San Francisco', 37.8199, -122.4783],
        ['niagara-falls', 'Niagara Falls', 'شلالات نياجرا', 'CA', 'Niagara Falls', 43.0962, -79.0377],
        ['christ-the-redeemer', 'Christ the Redeemer', 'تمثال المسيح الفادي', 'BR', 'Rio de Janeiro', -22.9519, -43.2105],
        ['machu-picchu', 'Machu Picchu', 'ماتشو بيتشو', 'PE', 'Cusco', -13.1631, -72.5450],
        ['chichen-itza', 'Chichen Itza', 'تشيتشن إيتزا', 'MX', 'Yucatan', 20.6843, -88.5678],
        ['giza-pyramids', 'Giza Pyramids', 'أهرامات الجيزة', 'EG', 'Giza', 29.9792, 31.1342],
        ['karnak-temple', 'Karnak Temple', 'معبد الكرنك', 'EG', 'Luxor', 25.7188, 32.6573],
        ['abu-simbel', 'Abu Simbel', 'معبد أبو سمبل', 'EG', 'Aswan', 22.3372, 31.6258],
        ['citadel-of-saladin', 'Citadel of Saladin', 'قلعة صلاح الدين', 'EG', 'Cairo', 30.0287, 31.2610],
        ['bibliotheca-alexandrina', 'Bibliotheca Alexandrina', 'مكتبة الإسكندرية', 'EG', 'Alexandria', 31.2089, 29.9092],
        ['hagia-sophia', 'Hagia Sophia', 'آيا صوفيا', 'TR', 'Istanbul', 41.0086, 28.9802],
        ['blue-mosque', 'Blue Mosque', 'المسجد الأزرق', 'TR', 'Istanbul', 41.0054, 28.9768],
        ['masjid-al-haram', 'Masjid al-Haram', 'المسجد الحرام', 'SA', 'Mecca', 21.4225, 39.8262],
        ['prophets-mosque', "Prophet's Mosque", 'المسجد النبوي', 'SA', 'Medina', 24.4672, 39.6111],
        ['burj-khalifa', 'Burj Khalifa', 'برج خليفة', 'AE', 'Dubai', 25.1972, 55.2744],
        ['burj-al-arab', 'Burj Al Arab', 'برج العرب', 'AE', 'Dubai', 25.1412, 55.1853],
        ['sheikh-zayed-grand-mosque', 'Sheikh Zayed Grand Mosque', 'جامع الشيخ زايد الكبير', 'AE', 'Abu Dhabi', 24.4128, 54.4750],
        ['petra', 'Petra', 'البتراء', 'JO', 'Petra', 30.3285, 35.4444],
        ['taj-mahal', 'Taj Mahal', 'تاج محل', 'IN', 'Agra', 27.1751, 78.0421],
        ['great-wall-badaling', 'Great Wall of China (Badaling)', 'سور الصين العظيم', 'CN', 'Beijing', 40.3599, 116.0204],
        ['forbidden-city', 'Forbidden City', 'المدينة المحرمة', 'CN', 'Beijing', 39.9163, 116.3972],
        ['tokyo-tower', 'Tokyo Tower', 'برج طوكيو', 'JP', 'Tokyo', 35.6586, 139.7454],
        ['shibuya-crossing', 'Shibuya Crossing', 'تقاطع شيبويا', 'JP', 'Tokyo', 35.6595, 139.7005],
        ['mount-fuji', 'Mount Fuji', 'جبل فوجي', 'JP', 'Fujinomiya', 35.3606, 138.7274],
        ['marina-bay-sands', 'Marina Bay Sands', 'مارينا باي ساندز', 'SG', 'Singapore', 1.2834, 103.8607],
        ['petronas-towers', 'Petronas Towers', 'برجا بتروناس', 'MY', 'Kuala Lumpur', 3.1579, 101.7116],
        ['sydney-opera-house', 'Sydney Opera House', 'دار أوبرا سيدني', 'AU', 'Sydney', -33.8568, 151.2153],
        ['table-mountain', 'Table Mountain', 'جبل الطاولة', 'ZA', 'Cape Town', -33.9628, 18.4098],
    ];
}
