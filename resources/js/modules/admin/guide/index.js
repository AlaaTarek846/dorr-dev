/**
 * In-panel screen guide (admin).
 *
 * - services/<key>.js  : the service map (groups -> screens by route name, first-run steps, message flow).
 * - screens/<service>/<name>.js : one guide per screen, keyed by its `route`. Loaded lazily, only when opened.
 *
 * Adding a guide = add a file under screens/ with `route` set to the screen's route name. The header "?" button
 * and the map pick it up on their own - no screen file needs editing.
 */
import ai from './services/ai';
import freelance from './services/freelance';
import marketing from './services/marketing';
import moving from './services/moving';
import rides from './services/rides';

export const guideServices = [ai, rides, moving, freelance, marketing];

const screenModules = import.meta.glob('./screens/*/*.js');

// route name -> loader. The route lives inside each file, so read it from the file name convention:
// screens/<service>/<route-name>.js where the file name is the route name without the "admin." prefix.
const loaders = Object.fromEntries(
    Object.entries(screenModules).map(([path, loader]) => {
        const file = path.split('/').pop().replace(/\.js$/, '');

        return [`admin.${file}`, loader];
    }),
);

export function hasScreenGuide(routeName) {
    return Boolean(routeName && loaders[routeName]);
}

export async function loadScreenGuide(routeName) {
    if (! hasScreenGuide(routeName)) {
        return null;
    }

    const module = await loaders[routeName]();

    return module.default;
}

export function findService(key) {
    return guideServices.find((service) => service.key === key) ?? null;
}

/** The service + group a route belongs to, for the "back to the map" link. */
export function findScreenPlace(routeName) {
    for (const service of guideServices) {
        for (const group of service.groups) {
            const screen = group.screens.find((item) => item.route === routeName);

            if (screen) {
                return { service, group, screen };
            }
        }
    }

    return null;
}

export function serviceProgress(service) {
    const screens = service.groups.flatMap((group) => group.screens);

    return {
        total: screens.length,
        documented: screens.filter((screen) => hasScreenGuide(screen.route)).length,
    };
}

/** Pick the viewer's language from a { ar, en } block, falling back to Arabic. */
export function localized(block, locale) {
    if (! block) {
        return null;
    }

    return block[locale] ?? block.ar ?? null;
}
