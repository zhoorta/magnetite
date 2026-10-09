package app.magnetite.player

import androidx.core.net.toUri
import androidx.media3.common.MediaItem
import androidx.media3.common.MediaMetadata
import app.magnetite.data.Track

/** The player's item for a track the viewer may play (`streamUrl` is set only for those). */
fun Track.toMediaItem(): MediaItem =
    MediaItem.Builder()
        .setMediaId(id.toString())
        .setUri(streamUrl!!)
        .setMediaMetadata(
            MediaMetadata.Builder()
                .setTitle(title)
                .setArtist(artist)
                .setAlbumTitle(album)
                .setArtworkUri(coverUrl?.toUri())
                .setIsBrowsable(false)
                .setIsPlayable(true)
                .build(),
        )
        .build()
