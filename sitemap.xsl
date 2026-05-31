<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="2.0"
  xmlns:html="http://www.w3.org/1999/xhtml"
  xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
  xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9">

  <xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes"/>

  <xsl:template match="/">
    <html xmlns="http://www.w3.org/1999/xhtml">
      <head>
        <title>XML Sitemap — OrbitDesk Workspace</title>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
        <meta name="robots" content="noindex, nofollow"/>
        <style>
          * { box-sizing: border-box; margin: 0; padding: 0; }
          body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; color: #1e293b; }
          header { background: #0B2D4E; color: #fff; padding: 20px 32px; }
          header h1 { font-size: 1.35rem; font-weight: 800; }
          header p  { font-size: .85rem; opacity: .7; margin-top: 4px; }
          .container { max-width: 1100px; margin: 32px auto; padding: 0 20px; }
          .summary-bar { background: white; border-radius: 10px; padding: 16px 24px; margin-bottom: 24px;
                         box-shadow: 0 1px 4px rgba(0,0,0,.08); display: flex; gap: 32px; flex-wrap: wrap; }
          .summary-bar .item { display: flex; flex-direction: column; }
          .summary-bar .label { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #64748b; font-weight: 600; }
          .summary-bar .value { font-size: 1.4rem; font-weight: 800; color: #0B2D4E; }
          table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px;
                  overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
          th { background: #0B2D4E; color: white; padding: 12px 16px; text-align: left; font-size: .78rem;
               font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
          td { padding: 10px 16px; border-bottom: 1px solid #f1f5f9; font-size: .85rem; vertical-align: middle; }
          tr:last-child td { border-bottom: none; }
          tr:hover td { background: #f8fafc; }
          td a { color: #1A8A4E; text-decoration: none; word-break: break-all; }
          td a:hover { text-decoration: underline; }
          .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: .7rem; font-weight: 700; }
          .pri-high   { background: #dcfce7; color: #166534; }
          .pri-mid    { background: #fef9c3; color: #854d0e; }
          .pri-low    { background: #f1f5f9; color: #475569; }
          .freq-daily   { background: #e0f2fe; color: #0369a1; }
          .freq-weekly  { background: #f0fdf4; color: #166534; }
          .freq-monthly { background: #fef3c7; color: #92400e; }
          .freq-yearly  { background: #fee2e2; color: #991b1b; }
          footer { text-align: center; padding: 24px; color: #94a3b8; font-size: .78rem; }
        </style>
      </head>
      <body>
        <header>
          <h1>🗺 XML Sitemap — OrbitDesk Workspace</h1>
          <p>This sitemap is submitted to Google Search Console and Bing Webmaster Tools.</p>
        </header>
        <div class="container">
          <div class="summary-bar">
            <div class="item">
              <span class="label">Total URLs</span>
              <span class="value"><xsl:value-of select="count(sitemap:urlset/sitemap:url)"/></span>
            </div>
            <div class="item">
              <span class="label">Generated</span>
              <span class="value" style="font-size:1rem">Live</span>
            </div>
          </div>
          <table>
            <tr>
              <th>#</th>
              <th>URL</th>
              <th>Last Modified</th>
              <th>Change Freq</th>
              <th>Priority</th>
            </tr>
            <xsl:for-each select="sitemap:urlset/sitemap:url">
              <xsl:variable name="pri" select="sitemap:priority"/>
              <xsl:variable name="freq" select="sitemap:changefreq"/>
              <tr>
                <td style="color:#94a3b8;font-size:.75rem"><xsl:value-of select="position()"/></td>
                <td>
                  <a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc"/></a>
                </td>
                <td style="white-space:nowrap;color:#64748b">
                  <xsl:value-of select="substring(sitemap:lastmod,1,10)"/>
                </td>
                <td>
                  <span>
                    <xsl:attribute name="class">badge
                      <xsl:choose>
                        <xsl:when test="$freq = 'daily'">freq-daily</xsl:when>
                        <xsl:when test="$freq = 'weekly'">freq-weekly</xsl:when>
                        <xsl:when test="$freq = 'monthly'">freq-monthly</xsl:when>
                        <xsl:otherwise>freq-yearly</xsl:otherwise>
                      </xsl:choose>
                    </xsl:attribute>
                    <xsl:value-of select="$freq"/>
                  </span>
                </td>
                <td>
                  <span>
                    <xsl:attribute name="class">badge
                      <xsl:choose>
                        <xsl:when test="$pri &gt;= 0.9">pri-high</xsl:when>
                        <xsl:when test="$pri &gt;= 0.6">pri-mid</xsl:when>
                        <xsl:otherwise>pri-low</xsl:otherwise>
                      </xsl:choose>
                    </xsl:attribute>
                    <xsl:value-of select="$pri"/>
                  </span>
                </td>
              </tr>
            </xsl:for-each>
          </table>
        </div>
        <footer>Generated dynamically by OrbitDesk Workspace • Submit to Google Search Console at /sitemap.xml</footer>
      </body>
    </html>
  </xsl:template>
</xsl:stylesheet>
