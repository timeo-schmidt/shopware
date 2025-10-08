/**
 * @sw-package framework
 */
import * as amplitude from '@amplitude/analytics-browser';
import type { BaseEvent, EventOptions } from '@amplitude/analytics-types';
import { string } from 'src/core/service/util.service';
import { TelemetryEvent, type EventTypes, type TrackableType } from '../../core/telemetry/types';

let userLoaded: Promise<void>;

/**
 * @private
 */
export default async function (): Promise<void> {
    const loginService = Shopware.Service('loginService');

    loginService.addOnLoginListener(() => {
        userLoaded = setUserId();
    });

    let defaultLanguageName = '';

    try {
        defaultLanguageName = await getDefaultLanguageName();
    } catch {
        defaultLanguageName = 'N/A';
    }

    amplitude.add({
        name: 'DefaultShopwareProperties',
        execute: (amplitudeEvent) => {
            const route = Shopware.Application.view?.router?.currentRoute
                ? {
                      sw_page_name: Shopware.Application.view.router.currentRoute.value.name,
                      sw_page_path: Shopware.Application.view.router.currentRoute.value.path,
                      sw_page_full_path: Shopware.Application.view.router.currentRoute.value.fullPath,
                  }
                : {};

            amplitudeEvent.event_properties = {
                ...amplitudeEvent.event_properties,
                sw_version: Shopware.Store.get('context').app.config.version,
                sw_shop_id: Shopware.Store.get('context').app.config.shopId,
                sw_user_agent: window.navigator.userAgent,
                sw_default_language: defaultLanguageName,
                sw_default_currency: Shopware.Context.app.systemCurrencyISOCode,
                sw_screen_width: window.screen.width,
                sw_screen_height: window.screen.height,
                sw_screen_orientation: window.screen.orientation.type.split('-')[0],
                ...route,
            };
            return Promise.resolve(amplitudeEvent);
        },
    });

    // check for consent
    // identify user

    amplitude.init('a04bb926f471ce883bc219814fc9577', undefined, {
        autocapture: false,
        serverZone: 'EU',
        appVersion: Shopware.Store.get('context').app.config.version as string,
        trackingOptions: {
            ipAddress: false,
            language: false,
            platform: false,
        },
        fetchRemoteConfig: false,
        // serverUrl: use proxy server url here, e.g. usage-data.shopware.io/product-analytics,
    });

    Shopware.Telemetry.addListener((telemetryEvent) => {
        if (!isTelemetryEvent(telemetryEvent)) {
            return;
        }

        if (isEventOfType('page_change', telemetryEvent)) {
            track('Page Viewed', {
                sw_route_from_name: telemetryEvent.detail.eventData.from.name,
                sw_route_from_href: telemetryEvent.detail.eventData.from.path,
                sw_route_to_name: telemetryEvent.detail.eventData.to.name,
                sw_route_to_href: telemetryEvent.detail.eventData.to.path,
                sw_route_to_query: telemetryEvent.detail.eventData.to.fullPath.split('?')[1],
            });
            return;
        }

        if (isEventOfType('user_interaction', telemetryEvent)) {
            const { target, originalEvent } = telemetryEvent.detail.eventData;

            const eventProperties: Record<string, TrackableType> = {};

            const capitalizedTagName = string.capitalizeString(target.tagName);
            const capitalizedEventName = string.capitalizeString(originalEvent.type);

            let eventName = `${capitalizedTagName} ${capitalizedEventName}`;

            if (target.tagName === 'A') {
                eventName = 'Link Visited';

                eventProperties.sw_link_href = target.getAttribute('href') ?? '';
                eventProperties.sw_link_type = target.getAttribute('target') === '_blank' ? 'external' : 'internal';
            }

            target.getAttributeNames().forEach((attributeName) => {
                if (attributeName.startsWith('data-analytics-')) {
                    const propertyName = string.snakeCase(attributeName.replace('data-analytics-', 'sw_element_'));
                    eventProperties[propertyName] = target.getAttribute(attributeName);
                }
            });

            if (originalEvent instanceof MouseEvent) {
                eventProperties.sw_pointer_x = originalEvent.clientX;
                eventProperties.sw_pointer_y = originalEvent.clientY;
                eventProperties.sw_pointer_button = originalEvent.buttons;
            }

            track(eventName, eventProperties);
        }
    });
}

function track(eventInput: string | BaseEvent, eventProperties?: Record<string, any>, eventOptions?: EventOptions): void {
    /* Wait for the next cycle so that the first page load is included in the set of events of the
     * logged-in user (happens just before the OnLogin event).
     */
    setTimeout(async () => {
        try {
            await userLoaded;
        } catch (error) {}

        amplitude.track(eventInput, eventProperties, eventOptions);
    }, 0);
}

async function getDefaultLanguageName(): Promise<string> {
    const languageRepository = Shopware.Service('repositoryFactory').create('language');
    const defaultLanguage = await languageRepository.get(Shopware.Context.api.systemLanguageId!);

    return defaultLanguage!.name;
}

async function setUserId(): Promise<void> {
    const currentUser = await Shopware.Service('userService').getUser();
    const shopId = Shopware.Store.get('context').app.config.shopId; // fetch after the user

    if (shopId && currentUser) {
        amplitude.setUserId(`${shopId}:${currentUser.data.id}`);
    }
}

function isTelemetryEvent(telemetryEvent: Event): telemetryEvent is TelemetryEvent<EventTypes> {
    return telemetryEvent instanceof TelemetryEvent;
}

function isEventOfType<N extends EventTypes>(
    eventType: N,
    telemetryEvent: TelemetryEvent<EventTypes>,
): telemetryEvent is TelemetryEvent<N> {
    return telemetryEvent.detail.eventType === eventType;
}
