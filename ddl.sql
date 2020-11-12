alter table receive_table
    add is_processed boolean default false;
comment on column receive_table.is_processed
    is 'Флаг сообщение обработано';

create index receive_table_is_processed_created_at_index
    on receive_table (is_processed, created_at);

CREATE TABLE if not exists smev_receive_message_attachment
(
    id                bigserial not null
        constraint smev_receive_message_attachment_pk
            primary key,
    receive_table_uid bigint    not null
        constraint
            smev_receive_message_attachment_receive_table_uid_fk
            references receive_table,
    filename          varchar   not null,
    detail            jsonb     not null,
    created_at        timestamp(0) default CURRENT_TIMESTAMP,
    updated_at        timestamp(0)
);
create unique index smev_message_attachment_request_id_uindex
    on public.smev_receive_message_attachment
        (receive_table_uid, filename);



