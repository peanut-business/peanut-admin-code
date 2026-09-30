'use strict'

function releaseMetadataVersion(metadata) {
  if (metadata && [2, 3].includes(metadata.schema_version)
    && metadata.protocol === `peanut.release-metadata.v${metadata.schema_version}`
    && typeof metadata.source_product_version === 'string'
    && (metadata.instance_version === null || typeof metadata.instance_version === 'string')) {
    return metadata.instance_version ?? metadata.source_product_version
  }
  if (metadata && metadata.schema_version === 1 && typeof metadata.version === 'string') {
    return metadata.version
  }
  throw new Error('unsupported release metadata identity')
}

module.exports = { releaseMetadataVersion }
