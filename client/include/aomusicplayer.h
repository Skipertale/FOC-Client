#ifndef AOMUSICPLAYER_H
#define AOMUSICPLAYER_H
#include "aoapplication.h"

#include <QDebug>
#include <QWidget>
#include <string.h>
#include <QFuture>
#include <QFutureWatcher>

class AOMusicPlayer {
public:
  AOMusicPlayer(QWidget *parent, AOApplication *p_ao_app);
  virtual ~AOMusicPlayer();
  void set_volume(int p_value, int channel = -1);
  void set_looping(bool loop_song, int channel = 0);
  void set_muted(bool toggle);

  const int m_channelmax = 16;

  QFutureWatcher<QString> music_watcher;

public slots:
  QString play(QString p_song, int channel = 0, bool loop = false,
            int effect_flags = 0);
  void stop(int channel = 0);

private:
  QWidget *m_parent;
  AOApplication *ao_app;

  bool m_muted = false;
  int m_volume[16] = {0};

  // Channel 0 = music
  // Channels 1-5 = ambience (per-area)
  // Channels 6-7 = SFX (URL streaming)
  // Channels 8-15 = reserved
  HSTREAM m_stream_list[16] = {0};
  HSYNC loop_sync[16] = {0};

  /**
   * @brief The starting sample of the AB-Loop.
   */
  unsigned int loop_start[16] = {0};

  /**
   * @brief The end sample of the AB-Loop.
   */
  unsigned int loop_end[16] = {0};
};

#endif // AOMUSICPLAYER_H
