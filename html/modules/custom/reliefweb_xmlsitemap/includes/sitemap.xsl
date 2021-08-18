<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="2.0"
    xmlns:html="http://www.w3.org/TR/REC-html40"
    xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
  <xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes"/>

  <!-- HTML page template -->
  <xsl:template match="/">
    <html>
      <head>
        <title>ReliefWeb sitemap</title>
        <style type="text/css">
          body {
            font-family: sans-serif;
            font-size: 16px;
          }
          table {
            color: #333;
            width: 100%;
            border-collapse:
            collapse; border-spacing: 0;
          }
          td,
          th {
            border: 1px solid #ccc;
            padding: 6px 8px;
            text-align: left;
          }
          th {
            background: #f3f3f3;
            font-weight: bold;
          }
          td {
            background: #fafafa;
          }
        </style>
      </head>
      <body>
        <h1>ReliefWeb sitemap</h1>
        <xsl:choose>
          <xsl:when test="//sitemap:sitemap"><xsl:call-template name="index"/></xsl:when>
          <xsl:otherwise><xsl:call-template name="page"/></xsl:otherwise>
        </xsl:choose>
      </body>
    </html>
  </xsl:template>

  <!-- Sitemap index template -->
  <xsl:template name="index">
    <h2>Index</h2>
    <table class="tablesorter siteindex">
      <thead>
        <tr>
          <th>URL</th>
          <th>Last modification</th>
        </tr>
      </thead>
      <tbody>
        <xsl:apply-templates select="sitemap:sitemapindex/sitemap:sitemap"></xsl:apply-templates>
      </tbody>
    </table>
  </xsl:template>

  <!-- Sitemap index - location template  -->
  <xsl:template match="sitemap:sitemap">
    <tr>
      <td>
        <xsl:variable name="loc"><xsl:value-of select="sitemap:loc"/></xsl:variable>
        <a href="{$loc}"><xsl:value-of select="$loc"/></a>
      </td>
      <td><xsl:value-of select="sitemap:lastmod"/></td>
    </tr>
  </xsl:template>

  <!-- Sitemap page template -->
  <xsl:template name="page">
    <p><a href="/sitemap.xml" rel="nofollow">View sitemap index</a></p>
    <table class="tablesorter sitemap">
      <thead>
        <tr>
          <th>URL</th>
          <th>Last modification</th>
          <th>Change frequency</th>
        </tr>
      </thead>
      <tbody>
        <xsl:apply-templates select="sitemap:urlset/sitemap:url"></xsl:apply-templates>
      </tbody>
    </table>
  </xsl:template>

  <!-- Sitemap page - location template -->
  <xsl:template match="sitemap:url">
    <tr>
      <td>
        <xsl:variable name="loc"><xsl:value-of select="sitemap:loc"/></xsl:variable>
        <a href="{$loc}" ref="nofollow noopener" target="_blank"><xsl:value-of select="$loc"/></a>
      </td>
      <td><xsl:value-of select="sitemap:lastmod"/></td>
      <td><xsl:value-of select="sitemap:changefreq"/></td>
    </tr>
  </xsl:template>
</xsl:stylesheet>
