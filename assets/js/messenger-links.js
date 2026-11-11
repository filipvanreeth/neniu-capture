export function messengerLinks(url, message) {
    const text = message.trim();
    const whatsapp = new URL('https://wa.me/');
    whatsapp.searchParams.set('text', text ? `${text}\n\n${url}` : url);
    const telegram = new URL('https://t.me/share/url');
    telegram.searchParams.set('url', url);
    if (text) telegram.searchParams.set('text', text);
    return { whatsapp: whatsapp.href, telegram: telegram.href };
}
