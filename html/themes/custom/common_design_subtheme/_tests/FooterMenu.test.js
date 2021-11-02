import env from './_env'

describe('FooterLinks', () => {
  beforeAll(async() => {
    await page.goto(env.baseUrl);
  });

  it('should contain specific links in the Footer menu', async() => {
    const footerMenuItems = ["Blog", "Labs", "About", "Terms", "Contact"];
    const footerMenuItemsHref = ["http://rwint9-site.test/blog", "https://labs.reliefweb.int/", "http://rwint9-site.test/about", "http://rwint9-site.test/terms-conditions", "http://rwint9-site.test/contact"];
    const footerLinks = await page.$$eval('.cd-footer ul a', text => { return text.map(text => text.textContent) });
    const footerLinksHref = await page.$$eval('.cd-footer ul a', anchors => { return anchors.map(anchor => anchor.href) });
    await expect(footerLinks).toEqual(expect.arrayContaining(footerMenuItems));
    await expect(footerLinksHref).toEqual(expect.arrayContaining(footerMenuItemsHref));
  });
});
