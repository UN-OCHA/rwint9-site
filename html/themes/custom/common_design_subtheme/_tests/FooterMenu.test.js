import env from './_env'

describe('FooterLinks', () => {
  beforeAll(async() => {
    await page.goto(env.baseUrl);
  });

  it('should contain specific links in the Footer menu', async() => {
    const footerMenuItems = ["Blog", "Labs", "About", "Terms", "Contact"];
    const footerMenuItemsHref = [env.baseUrl + "/blog", "https://labs.reliefweb.int/", env.baseUrl + "/about", env.baseUrl + "/terms-conditions", env.baseUrl + "/contact"];
    const footerLinks = await page.$$eval('.cd-footer ul a', text => { return text.map(text => text.textContent) });
    const footerLinksHref = await page.$$eval('.cd-footer ul a', anchors => { return anchors.map(anchor => anchor.href) });
    await expect(footerLinks).toEqual(expect.arrayContaining(footerMenuItems));
    await expect(footerLinksHref).toEqual(expect.arrayContaining(footerMenuItemsHref));
  });
});
